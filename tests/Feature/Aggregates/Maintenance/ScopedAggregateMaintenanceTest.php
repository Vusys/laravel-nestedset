<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Maintenance;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\Tests\Fixtures\Models\ScopedArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Aggregate maintenance on a *scoped* model. The scope predicates in
 * both maintenance strategies — the delta path (SUM) and the recompute
 * path (MIN) — must confine every write to the acting node's partition,
 * so a mutation in one tenant's tree never disturbs another's.
 *
 * ScopedArea is the only fixture that combines partitioned trees with
 * maintained aggregates, so these are the cases that exercise the
 * `foreach (scope)` WHERE clause in DeltaMaintenance and the scope
 * JOIN/WHERE in RecomputeMaintenance.
 */
final class ScopedAggregateMaintenanceTest extends TestCase
{
    /**
     * @return array{root: ScopedArea, a: ScopedArea, b: ScopedArea}
     */
    private function buildTenantTree(int $tenantId, int $rootAmount, int $aAmount, int $bAmount): array
    {
        $root = new ScopedArea(['name' => "t{$tenantId}-root", 'tenant_id' => $tenantId, 'amount' => $rootAmount]);
        $root->saveAsRoot();

        $a = new ScopedArea(['name' => "t{$tenantId}-a", 'tenant_id' => $tenantId, 'amount' => $aAmount]);
        $a->appendToNode($root)->save();

        $b = new ScopedArea(['name' => "t{$tenantId}-b", 'tenant_id' => $tenantId, 'amount' => $bAmount]);
        $b->appendToNode($root)->save();

        return ['root' => $root, 'a' => $a, 'b' => $b];
    }

    #[Test]
    public function delta_sum_and_recompute_min_stay_within_the_acting_scope(): void
    {
        // Two independent tenant forests.
        $t1 = $this->buildTenantTree(tenantId: 1, rootAmount: 10, aAmount: 5, bAmount: 3);
        $t2 = $this->buildTenantTree(tenantId: 2, rootAmount: 100, aAmount: 70, bAmount: 40);

        // SUM (delta) and MIN (recompute) rolled up per tenant.
        $this->assertSame(18, $t1['root']->refresh()->amount_total); // 10 + 5 + 3
        $this->assertSame(3, $t1['root']->amount_min);
        $this->assertSame(210, $t2['root']->refresh()->amount_total); // 100 + 70 + 40
        $this->assertSame(40, $t2['root']->amount_min);

        // Delete tenant 1's current minimum (b, amount 3). SUM deltas the
        // ancestors down; MIN recomputes the subtree. Both must scope to
        // tenant 1 only.
        $t1['b']->refresh()->forceDelete();

        $this->assertSame(15, $t1['root']->refresh()->amount_total); // 18 - 3
        $this->assertSame(5, $t1['root']->amount_min); // new minimum among {10, 5}

        // Tenant 2 is untouched by tenant 1's mutation.
        $this->assertSame(210, $t2['root']->refresh()->amount_total);
        $this->assertSame(40, $t2['root']->amount_min);
    }

    #[Test]
    public function update_in_one_scope_does_not_leak_into_another(): void
    {
        $t1 = $this->buildTenantTree(tenantId: 1, rootAmount: 10, aAmount: 5, bAmount: 3);
        $t2 = $this->buildTenantTree(tenantId: 2, rootAmount: 100, aAmount: 70, bAmount: 40);

        // Raise tenant 1 child A by 100 (delta SUM update).
        $a = $t1['a']->refresh();
        $a->amount = 105;
        $a->save();

        $this->assertSame(118, $t1['root']->refresh()->amount_total); // 18 + 100
        $this->assertSame(210, $t2['root']->refresh()->amount_total); // unchanged
    }

    #[Test]
    public function aggregate_errors_without_an_anchor_throws_on_a_scoped_model(): void
    {
        // A scoped model can't be repaired/inspected forest-wide: every
        // aggregate operation must be anchored to one tree so it stays
        // inside a single partition. The anchorless call is refused.
        $this->expectException(ScopeViolationException::class);
        $this->expectExceptionMessage('pass an anchor node to scope this operation');

        ScopedArea::aggregateErrors();
    }
}
