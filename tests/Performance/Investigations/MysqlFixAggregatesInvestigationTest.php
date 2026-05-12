<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Performance\Investigations;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Tests\Performance\Fixtures\TreeShapes;
use Vusys\NestedSet\Tests\Performance\PerformanceTestCase;

/**
 * Investigation harness for the MySQL/MariaDB fixAggregates plateau.
 *
 * Skipped unless `INVESTIGATE=1` so it doesn't bloat the perf suite.
 * Designed to run once and have a human read the printed plan + timings.
 *
 * First round identified the win: wrapping the JOIN+GROUP BY in a derived
 * table changes MySQL's plan from nested-loop-with-temporary-table-aggregate
 * to hash-join + filter + aggregate — 18.3s → 3.6s at N=10K.
 *
 * Round 2 verifies the win with:
 *   - All 7 inclusive aggregates (the real workload, including AVG companions)
 *   - The BETWEEN-on-lft-only simplification (equivalent predicate, single index column)
 *   - PostgreSQL + SQLite (no regression)
 */
final class MysqlFixAggregatesInvestigationTest extends PerformanceTestCase
{
    public function test_explore_query_shapes(): void
    {
        if (getenv('INVESTIGATE') !== '1') {
            $this->markTestSkipped('Set INVESTIGATE=1 to run this investigation.');
        }

        $driver = DB::connection()->getDriverName();
        $n = (int) (getenv('INVESTIGATE_N') ?: '10000');

        DB::table('areas')->delete();
        TreeShapes::balancedFanout('areas', nodes: $n, fanout: 10);

        fwrite(STDOUT, "\n=== Investigation R2: fixAggregates SELECT shape ({$driver}, N={$n}) ===\n");

        $this->dumpIndexes($driver);

        // --- 1. Baseline: current shape, full 7-aggregate inclusive set ------
        $currentSql = $this->currentFullShape();
        fwrite(STDOUT, "\n--- Current shape, full 7 aggregates ---\n");
        $this->timeAndExplain('current_full', $currentSql, $driver);

        // --- 2. Derived-table, full 7 aggregates, two-inequality join ---------
        $derivedTwoIneqSql = $this->derivedFullShape(joinPredicate: 'i.lft >= o.lft AND i.rgt <= o.rgt');
        fwrite(STDOUT, "\n--- Derived table, full 7 agg, two-inequality predicate ---\n");
        $this->timeAndExplain('derived_full_two_ineq', $derivedTwoIneqSql, $driver);

        // --- 3. Derived-table, full 7 aggregates, BETWEEN on lft only --------
        $derivedBetweenSql = $this->derivedFullShape(joinPredicate: 'i.lft BETWEEN o.lft AND o.rgt');
        fwrite(STDOUT, "\n--- Derived table, full 7 agg, BETWEEN on lft only ---\n");
        $this->timeAndExplain('derived_full_between_lft', $derivedBetweenSql, $driver);

        $this->addToAssertionCount(1);
    }

    private function dumpIndexes(string $driver): void
    {
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $rows = DB::select('SHOW INDEX FROM areas');
            fwrite(STDOUT, "\n--- Indexes ---\n");
            foreach ($rows as $row) {
                $arr = (array) $row;
                fwrite(STDOUT, sprintf(
                    "  %-40s seq=%d col=%-22s card=%s\n",
                    $arr['Key_name'],
                    $arr['Seq_in_index'],
                    $arr['Column_name'],
                    (string) ($arr['Cardinality'] ?? 'null'),
                ));
            }
        } elseif ($driver === 'pgsql') {
            $rows = DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'areas'");
            fwrite(STDOUT, "\n--- Indexes ---\n");
            foreach ($rows as $row) {
                $arr = (array) $row;
                fwrite(STDOUT, "  {$arr['indexname']}\n    {$arr['indexdef']}\n");
            }
        }
    }

    private function timeAndExplain(string $label, string $sql, string $driver): void
    {
        // Warmup
        DB::select($sql);

        $t0 = microtime(true);
        $rows = DB::select($sql);
        $elapsedMs = (microtime(true) - $t0) * 1000;

        fwrite(STDOUT, sprintf("  [%s] %d rows, %.1f ms\n", $label, count($rows), $elapsedMs));

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $plan = DB::select('EXPLAIN ANALYZE '.$sql);
        } elseif ($driver === 'pgsql') {
            $plan = DB::select('EXPLAIN (ANALYZE, BUFFERS) '.$sql);
        } else {
            return;
        }

        fwrite(STDOUT, "  Plan:\n");
        foreach ($plan as $row) {
            $arr = (array) $row;
            foreach ($arr as $v) {
                foreach (explode("\n", (string) $v) as $line) {
                    fwrite(STDOUT, "    {$line}\n");
                }
            }
        }
    }

    /**
     * Full inclusive 7-aggregate set as fixAggregates() actually issues:
     * tickets_total (SUM), tickets_count_all (COUNT), tickets_avg (AVG),
     * tickets_min (MIN), tickets_max (MAX), tickets_avg__sum (SUM),
     * tickets_avg__count (COUNT) — every one of these is inclusive by
     * default, so this is what the package emits today.
     */
    private function currentFullShape(): string
    {
        return 'SELECT outer_a.id AS id,'
            .' COALESCE(SUM(inner_a.tickets), 0) AS computed_tickets_total,'
            .' outer_a.tickets_total AS stored_tickets_total,'
            .' COUNT(inner_a.tickets) AS computed_tickets_count_all,'
            .' outer_a.tickets_count_all AS stored_tickets_count_all,'
            .' AVG(inner_a.tickets) AS computed_tickets_avg,'
            .' outer_a.tickets_avg AS stored_tickets_avg,'
            .' MIN(inner_a.tickets) AS computed_tickets_min,'
            .' outer_a.tickets_min AS stored_tickets_min,'
            .' MAX(inner_a.tickets) AS computed_tickets_max,'
            .' outer_a.tickets_max AS stored_tickets_max,'
            .' COALESCE(SUM(inner_a.tickets), 0) AS computed_tickets_avg__sum,'
            .' outer_a.tickets_avg__sum AS stored_tickets_avg__sum,'
            .' COUNT(inner_a.tickets) AS computed_tickets_avg__count,'
            .' outer_a.tickets_avg__count AS stored_tickets_avg__count'
            .' FROM areas AS outer_a'
            .' LEFT JOIN areas AS inner_a'
            .' ON inner_a.lft >= outer_a.lft AND inner_a.rgt <= outer_a.rgt'
            .' WHERE 1 = 1'
            .' GROUP BY outer_a.id, outer_a.tickets_total, outer_a.tickets_count_all,'
            .' outer_a.tickets_avg, outer_a.tickets_min, outer_a.tickets_max,'
            .' outer_a.tickets_avg__sum, outer_a.tickets_avg__count';
    }

    private function derivedFullShape(string $joinPredicate): string
    {
        return 'SELECT outer_a.id AS id,'
            .' agg.computed_tickets_total,'
            .' outer_a.tickets_total AS stored_tickets_total,'
            .' agg.computed_tickets_count_all,'
            .' outer_a.tickets_count_all AS stored_tickets_count_all,'
            .' agg.computed_tickets_avg,'
            .' outer_a.tickets_avg AS stored_tickets_avg,'
            .' agg.computed_tickets_min,'
            .' outer_a.tickets_min AS stored_tickets_min,'
            .' agg.computed_tickets_max,'
            .' outer_a.tickets_max AS stored_tickets_max,'
            .' agg.computed_tickets_avg__sum,'
            .' outer_a.tickets_avg__sum AS stored_tickets_avg__sum,'
            .' agg.computed_tickets_avg__count,'
            .' outer_a.tickets_avg__count AS stored_tickets_avg__count'
            .' FROM areas AS outer_a'
            .' INNER JOIN ('
            .'   SELECT o.id AS outer_id,'
            .'   COALESCE(SUM(i.tickets), 0) AS computed_tickets_total,'
            .'   COUNT(i.tickets) AS computed_tickets_count_all,'
            .'   AVG(i.tickets) AS computed_tickets_avg,'
            .'   MIN(i.tickets) AS computed_tickets_min,'
            .'   MAX(i.tickets) AS computed_tickets_max,'
            .'   COALESCE(SUM(i.tickets), 0) AS computed_tickets_avg__sum,'
            .'   COUNT(i.tickets) AS computed_tickets_avg__count'
            .'   FROM areas o'
            ."   INNER JOIN areas i ON {$joinPredicate}"
            .'   GROUP BY o.id'
            .' ) agg ON agg.outer_id = outer_a.id';
    }
}
