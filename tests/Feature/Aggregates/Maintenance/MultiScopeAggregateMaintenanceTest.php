<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Maintenance;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Tests\Fixtures\Models\MultiScopedBranch;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Coverage for the multi-column scope path. The package iterates
 * `foreach ($scope as $col => $value)` (and the parallel
 * `foreach ($scopeCols as $col)`) at several SQL-building sites; every
 * other scoped fixture has exactly one column, so a `$sql .= ...`
 * accumulator mutated to `$sql = ...` would still iterate exactly once
 * and pass. MultiScopedBranch declares two scope columns so a single
 * iteration is no longer enough — the mutation either drops the
 * SELECT prefix (yielding malformed SQL that throws) or drops the
 * first scope predicate (yielding cross-partition leakage detectable
 * at aggregate-correctness time).
 *
 * Trees built per partition (same shape, different totals):
 *
 *   (tenant=1, site=1): chain   root(10) → a(5) → b(3)
 *   (tenant=1, site=2): branching root(100) with children x(70), y(40)
 *
 * Same `tenant_id`, different `site_id` — so a mutation that retains
 * only `tenant_id = ?` would see *both* partitions as one tree, and a
 * mutation that retains only `site_id = ?` would mix partitions across
 * tenants in a larger forest.
 */
final class MultiScopeAggregateMaintenanceTest extends TestCase
{
    /**
     * @return array{root: MultiScopedBranch, a: MultiScopedBranch, b: MultiScopedBranch}
     */
    private function buildChain(int $tenantId, int $siteId, int $rootAmt, int $aAmt, int $bAmt): array
    {
        $root = new MultiScopedBranch([
            'name' => "t{$tenantId}-s{$siteId}-root",
            'tenant_id' => $tenantId,
            'site_id' => $siteId,
            'tickets' => $rootAmt,
        ]);
        $root->saveAsRoot();

        $a = new MultiScopedBranch([
            'name' => "t{$tenantId}-s{$siteId}-a",
            'tenant_id' => $tenantId,
            'site_id' => $siteId,
            'tickets' => $aAmt,
        ]);
        $a->appendToNode($root)->save();

        $b = new MultiScopedBranch([
            'name' => "t{$tenantId}-s{$siteId}-b",
            'tenant_id' => $tenantId,
            'site_id' => $siteId,
            'tickets' => $bAmt,
        ]);
        $b->appendToNode($a)->save();

        return ['root' => $root, 'a' => $a, 'b' => $b];
    }

    /**
     * @return array{root: MultiScopedBranch, x: MultiScopedBranch, y: MultiScopedBranch}
     */
    private function buildBranching(int $tenantId, int $siteId, int $rootAmt, int $xAmt, int $yAmt): array
    {
        $root = new MultiScopedBranch([
            'name' => "t{$tenantId}-s{$siteId}-root",
            'tenant_id' => $tenantId,
            'site_id' => $siteId,
            'tickets' => $rootAmt,
        ]);
        $root->saveAsRoot();

        $x = new MultiScopedBranch([
            'name' => "t{$tenantId}-s{$siteId}-x",
            'tenant_id' => $tenantId,
            'site_id' => $siteId,
            'tickets' => $xAmt,
        ]);
        $x->appendToNode($root)->save();

        $y = new MultiScopedBranch([
            'name' => "t{$tenantId}-s{$siteId}-y",
            'tenant_id' => $tenantId,
            'site_id' => $siteId,
            'tickets' => $yAmt,
        ]);
        $y->appendToNode($root)->save();

        return ['root' => $root, 'x' => $x, 'y' => $y];
    }

    public function test_delta_aggregates_stay_inside_the_composite_scope(): void
    {
        $tA = $this->buildChain(tenantId: 1, siteId: 1, rootAmt: 10, aAmt: 5, bAmt: 3);
        $tB = $this->buildBranching(tenantId: 1, siteId: 2, rootAmt: 100, xAmt: 70, yAmt: 40);

        $this->assertSame(18, $tA['root']->refresh()->tickets_total);  // 10 + 5 + 3
        $this->assertSame(3, $tA['root']->tickets_count);
        $this->assertSame(210, $tB['root']->refresh()->tickets_total); // 100 + 70 + 40
        $this->assertSame(3, $tB['root']->tickets_count);

        $tA['b']->refresh()->forceDelete();

        $this->assertSame(15, $tA['root']->refresh()->tickets_total);  // 18 - 3
        $this->assertSame(2, $tA['root']->tickets_count);
        $this->assertSame(210, $tB['root']->refresh()->tickets_total); // partition B untouched
        $this->assertSame(3, $tB['root']->tickets_count);
    }

    /**
     * `AggregateDiffer::isChainShape()` builds its detector SQL via
     * `$sql .= " AND {$col} = ?"` per scope column. Force the path by
     * giving the anchor partition a chain shape and a sibling partition
     * a non-chain shape — if the mutation collapses the `.= → =`, the
     * built SQL drops the `SELECT 1 FROM ... WHERE 1 = 1` prefix and
     * throws on execution. Catching the regression doesn't require an
     * assertion on the result; just that `aggregateErrors()` runs.
     */
    public function test_chain_shape_detector_runs_with_composite_scope(): void
    {
        $tA = $this->buildChain(tenantId: 1, siteId: 1, rootAmt: 10, aAmt: 5, bAmt: 3);
        $this->buildBranching(tenantId: 1, siteId: 2, rootAmt: 100, xAmt: 70, yAmt: 40);

        // aggregateErrors() routes through AggregateDiffer::selectStoredAndComputed,
        // which calls isChainShape() iff (! anyFiltered && ! anyRecomputeOnly).
        // MultiScopedBranch has only SUM + COUNT, both delta-maintainable, so
        // both gates pass and isChainShape() is invoked.
        $errors = MultiScopedBranch::aggregateErrors($tA['root']->refresh());

        $this->assertSame(0, $errors['tickets_total'] ?? -1);
        $this->assertSame(0, $errors['tickets_count'] ?? -1);
    }

    /**
     * Sanity-check that the loop's emitted predicate actually mentions
     * both scope columns by capturing the executed query. Belt to the
     * other test's braces: even on backends where dropping the SELECT
     * prefix doesn't raise (e.g. permissive sqlite parses), the captured
     * SQL must list `tenant_id` and `site_id` as separate predicates.
     */
    public function test_chain_shape_detector_sql_references_every_scope_column(): void
    {
        $tA = $this->buildChain(tenantId: 1, siteId: 1, rootAmt: 10, aAmt: 5, bAmt: 3);

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            MultiScopedBranch::aggregateErrors($tA['root']->refresh());
        } finally {
            DB::disableQueryLog();
        }

        $log = DB::getQueryLog();
        $matched = false;
        foreach ($log as $entry) {
            $sql = $entry['query'];
            // The chain-shape probe is the only query the suite emits that
            // groups by parent_id with a HAVING clause.
            if (str_contains($sql, 'GROUP BY') && str_contains($sql, 'parent_id') && str_contains($sql, 'HAVING')) {
                $this->assertStringContainsString('tenant_id', $sql);
                $this->assertStringContainsString('site_id', $sql);
                $matched = true;
                break;
            }
        }

        $this->assertTrue($matched, 'isChainShape() probe query was not observed');
    }
}
