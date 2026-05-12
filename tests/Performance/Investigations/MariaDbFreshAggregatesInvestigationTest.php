<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Performance\Investigations;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Tests\Performance\Fixtures\TreeShapes;
use Vusys\NestedSet\Tests\Performance\PerformanceTestCase;

/**
 * Phase R: find a shape for `withFreshAggregates()` on MariaDB.
 *
 * Current state (v0.7.0): MariaDB stays on K correlated sub-queries
 * because the SQL LATERAL keyword is rejected. At N=10K with the full
 * 5-aggregate set the read-fresh path takes ~85 s on CI runners.
 *
 * Candidate shapes (one PHPUnit run for everything since the test
 * harness drops the areas table at tearDown):
 *
 *   A. Current shape — K correlated sub-queries on the outer SELECT.
 *   B. Derived LEFT JOIN — full-table aggregation joined back to outer.
 *   C. Derived + SET SESSION split_materialized=off — block the
 *      lateralisation optimiser that bit fixAggregates pre-v0.6.0.
 *   D. Derived + filter inner `o` to user's id-set (small queries).
 *   E. Same as D + split_materialized=off.
 */
final class MariaDbFreshAggregatesInvestigationTest extends PerformanceTestCase
{
    public function test_explore_fresh_shapes(): void
    {
        if (getenv('INVESTIGATE') !== '1') {
            $this->markTestSkipped('Set INVESTIGATE=1 to run this investigation.');
        }

        $driver = DB::connection()->getDriverName();
        if ($driver !== 'mysql' || stripos($this->serverVersion(), 'mariadb') === false) {
            $this->markTestSkipped('Only runs against MariaDB.');
        }

        $n = (int) (getenv('INVESTIGATE_N') ?: '10000');

        DB::table('areas')->delete();
        TreeShapes::balancedFanout('areas', nodes: $n, fanout: 10);

        fwrite(STDOUT, "\n=== Phase R: withFreshAggregates MariaDB shapes (N={$n}) ===\n");

        // --- A. Baseline: K correlated sub-queries (current shape) -----------
        $aSql = $this->correlatedShape();
        fwrite(STDOUT, "\n--- A. Correlated sub-queries (current) ---\n");
        $this->timeShape('A_correlated', $aSql);

        // --- B. Derived LEFT JOIN, no o-filter ------------------------------
        $bSql = $this->derivedShape(filterByOuterId: false);
        fwrite(STDOUT, "\n--- B. Derived LEFT JOIN (no o-filter) ---\n");
        $this->timeShape('B_derived_full', $bSql);

        // --- C. Derived + SET SESSION split_materialized=off ---------------
        DB::statement("SET SESSION optimizer_switch='split_materialized=off'");
        fwrite(STDOUT, "\n--- C. Derived LEFT JOIN + split_materialized=off ---\n");
        $this->timeShape('C_derived_split_off', $bSql);
        DB::statement('SET SESSION optimizer_switch=DEFAULT');

        // --- D. Derived with o-filter (simulate small user query: 10 rows) ---
        // We test "user picked top-level depth=0 nodes (1 root)" and "user
        // picked depth=1 (10 direct children of root)" as small-query cases.
        $smallSql = $this->derivedShape(filterByOuterId: true, idSubquery: 'SELECT id FROM areas WHERE depth = 1');
        fwrite(STDOUT, "\n--- D. Derived + o-filter to depth=1 nodes (small) ---\n");
        $this->timeShape('D_derived_small', $smallSql);

        // --- E. D + split_materialized=off ---------------------------------
        DB::statement("SET SESSION optimizer_switch='split_materialized=off'");
        fwrite(STDOUT, "\n--- E. D + split_materialized=off ---\n");
        $this->timeShape('E_derived_small_split_off', $smallSql);
        DB::statement('SET SESSION optimizer_switch=DEFAULT');

        $this->addToAssertionCount(1);
    }

    private function serverVersion(): string
    {
        $rows = DB::select('SELECT VERSION() AS v');

        return isset($rows[0]) ? (string) ((array) $rows[0])['v'] : '';
    }

    private function timeShape(string $label, string $sql): void
    {
        DB::select($sql); // warmup
        $t0 = microtime(true);
        $rows = DB::select($sql);
        $ms = (microtime(true) - $t0) * 1000;
        fwrite(STDOUT, sprintf("  [%s] %d rows, %.1f ms\n", $label, count($rows), $ms));

        $plan = DB::select('ANALYZE FORMAT=JSON '.$sql);
        // Print only the abbreviated plan
        $json = isset($plan[0]) ? (array) $plan[0] : [];
        foreach ($json as $v) {
            $decoded = is_string($v) ? json_decode($v, true) : $v;
            $summary = $this->extractPlanSummary($decoded);
            fwrite(STDOUT, "  Plan: {$summary}\n");
        }
    }

    /**
     * Walk MariaDB's JSON plan and pull the noteworthy bits: table access
     * types, r_total_time_ms per node, and whether 'lateral_derived' shows up.
     */
    private function extractPlanSummary(mixed $node, int $depth = 0): string
    {
        if (! is_array($node)) {
            return '';
        }
        $out = [];
        if (isset($node['table']) && is_array($node['table'])) {
            $t = $node['table'];
            $name = is_scalar($t['table_name'] ?? null) ? (string) $t['table_name'] : '?';
            $accessType = is_scalar($t['access_type'] ?? null) ? (string) $t['access_type'] : '?';
            $rtime = is_scalar($t['r_total_time_ms'] ?? null) ? (string) $t['r_total_time_ms'] : '?';
            $out[] = str_repeat('  ', $depth)."{$name}({$accessType}, r_time={$rtime})";
        }
        foreach ($node as $v) {
            if (is_array($v)) {
                $sub = $this->extractPlanSummary($v, $depth + 1);
                if ($sub !== '') {
                    $out[] = $sub;
                }
            }
        }

        return implode("\n  Plan: ", $out);
    }

    private function correlatedShape(): string
    {
        return 'SELECT areas.*,'
            .' (SELECT COALESCE(SUM(d.tickets), 0) FROM areas d WHERE d.lft >= areas.lft AND d.rgt <= areas.rgt) AS tickets_total,'
            .' (SELECT COUNT(*) FROM areas d WHERE d.lft >= areas.lft AND d.rgt <= areas.rgt) AS tickets_count_all,'
            .' (SELECT AVG(d.tickets) FROM areas d WHERE d.lft >= areas.lft AND d.rgt <= areas.rgt) AS tickets_avg,'
            .' (SELECT MIN(d.tickets) FROM areas d WHERE d.lft >= areas.lft AND d.rgt <= areas.rgt) AS tickets_min,'
            .' (SELECT MAX(d.tickets) FROM areas d WHERE d.lft >= areas.lft AND d.rgt <= areas.rgt) AS tickets_max'
            .' FROM areas';
    }

    private function derivedShape(bool $filterByOuterId, ?string $idSubquery = null): string
    {
        $oFilter = $filterByOuterId && $idSubquery !== null ? " WHERE o.id IN ({$idSubquery})" : '';
        $outerWhere = $filterByOuterId && $idSubquery !== null ? " WHERE areas.id IN ({$idSubquery})" : '';

        return 'SELECT areas.*, agg.tickets_total, agg.tickets_count_all, agg.tickets_avg, agg.tickets_min, agg.tickets_max'
            .' FROM areas'
            .' LEFT JOIN ('
            .' SELECT o.id AS outer_id,'
            .' COALESCE(SUM(d.tickets), 0) AS tickets_total,'
            .' COUNT(*) AS tickets_count_all,'
            .' AVG(d.tickets) AS tickets_avg,'
            .' MIN(d.tickets) AS tickets_min,'
            .' MAX(d.tickets) AS tickets_max'
            ." FROM areas o INNER JOIN areas d ON d.lft BETWEEN o.lft AND o.rgt{$oFilter}"
            .' GROUP BY o.id'
            .') AS agg ON agg.outer_id = areas.id'
            .$outerWhere;
    }
}
