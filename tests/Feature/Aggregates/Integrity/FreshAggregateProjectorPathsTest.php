<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Integrity;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Query\Aggregates\Read\FreshAggregateProjector;
use Vusys\NestedSet\Tests\Fixtures\Models\Monster;
use Vusys\NestedSet\Tests\Fixtures\Models\ScopedArea;
use Vusys\NestedSet\Tests\Fixtures\Models\SoftBranch;
use Vusys\NestedSet\Tests\Fixtures\Models\TypedArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Targets the fresh-read code paths in
 * {@see FreshAggregateProjector}
 * that the unscoped, non-soft Area-based tests never reach on the
 * correlated-subquery backend:
 *
 *  - the scope predicate in the correlated subquery and in scalar()
 *    (only a scoped model with overlapping per-tenant lft/rgt ranges
 *    makes the predicate observable),
 *  - the soft-delete predicate and the filter predicate in the quantile
 *    subquery,
 *  - the early-return / listener-skip when a model declares no SQL
 *    aggregates.
 */
final class FreshAggregateProjectorPathsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    private function asInt(mixed $value): int
    {
        $this->assertNotNull($value, 'Expected numeric, got null.');
        $this->assertTrue(is_numeric($value), 'Expected numeric, got '.get_debug_type($value));

        return (int) $value;
    }

    private function asFloat(mixed $value): float
    {
        $this->assertNotNull($value, 'Expected numeric, got null.');
        $this->assertTrue(is_numeric($value), 'Expected numeric, got '.get_debug_type($value));

        return (float) $value;
    }

    /**
     * Two tenants, each built independently with `saveAsRoot()`, so both
     * trees occupy the *same* lft/rgt range (1..6). A fresh aggregate that
     * dropped the scope predicate would read across both tenants; the
     * scope clause is what keeps each read inside its own partition.
     *
     * @return array{t1root: ScopedArea, t2root: ScopedArea}
     */
    private function buildTwoOverlappingTenants(): array
    {
        $t1root = new ScopedArea(['name' => 't1-root', 'tenant_id' => 1, 'amount' => 10]);
        $t1root->saveAsRoot();
        (new ScopedArea(['name' => 't1-a', 'tenant_id' => 1, 'amount' => 5]))->appendToNode($t1root)->save();
        (new ScopedArea(['name' => 't1-b', 'tenant_id' => 1, 'amount' => 3]))->appendToNode($t1root->refresh())->save();

        $t2root = new ScopedArea(['name' => 't2-root', 'tenant_id' => 2, 'amount' => 100]);
        $t2root->saveAsRoot();
        (new ScopedArea(['name' => 't2-a', 'tenant_id' => 2, 'amount' => 70]))->appendToNode($t2root)->save();
        (new ScopedArea(['name' => 't2-b', 'tenant_id' => 2, 'amount' => 40]))->appendToNode($t2root->refresh())->save();

        return ['t1root' => $t1root->refresh(), 't2root' => $t2root->refresh()];
    }

    #[Test]
    public function scalar_fresh_aggregate_is_confined_to_the_nodes_scope(): void
    {
        ['t1root' => $t1root, 't2root' => $t2root] = $this->buildTwoOverlappingTenants();

        // Inclusive subtree SUM within each tenant only. Without the scope
        // clause both roots would read all six rows (228).
        $this->assertSame(18, $this->asInt($t1root->freshAggregate('amount_total')));
        $this->assertSame(210, $this->asInt($t2root->freshAggregate('amount_total')));

        // MIN through the same scalar path.
        $this->assertSame(3, $this->asInt($t1root->freshAggregate('amount_min')));
        $this->assertSame(40, $this->asInt($t2root->freshAggregate('amount_min')));
    }

    #[Test]
    public function with_fresh_aggregates_correlated_subquery_is_scope_confined(): void
    {
        $this->buildTwoOverlappingTenants();

        /** @var array<string, int> $byName */
        $byName = ScopedArea::query()
            ->withFreshAggregates(['amount_total'])
            ->get()
            ->mapWithKeys(fn (ScopedArea $a): array => [$a->name => $this->asInt($a->amount_total)])
            ->all();

        $this->assertSame(18, $byName['t1-root']);
        $this->assertSame(210, $byName['t2-root']);
        // Leaves see only themselves within their tenant.
        $this->assertSame(5, $byName['t1-a']);
        $this->assertSame(70, $byName['t2-a']);
    }

    #[Test]
    public function quantile_fresh_read_is_scope_confined(): void
    {
        $this->buildTwoOverlappingTenants();

        /** @var array<string, float|null> $byName */
        $byName = ScopedArea::query()
            ->withFreshAggregates(['m' => Aggregate::median('amount')])
            ->get()
            ->mapWithKeys(fn (ScopedArea $a): array => [$a->name => $a->getAttribute('m')])
            ->all();

        // Tenant 1 root subtree {3, 5, 10} → 5; tenant 2 {40, 70, 100} → 70.
        // A scope-blind read would see {3,5,10,40,70,100} → 25 for both.
        $this->assertEqualsWithDelta(5.0, $this->asFloat($byName['t1-root']), 0.0001);
        $this->assertEqualsWithDelta(70.0, $this->asFloat($byName['t2-root']), 0.0001);
    }

    #[Test]
    public function quantile_fresh_read_excludes_trashed_descendants(): void
    {
        $root = new SoftBranch(['name' => 'root', 'tickets' => 10, 'active' => 1]);
        $root->saveAsRoot();
        (new SoftBranch(['name' => 'a', 'tickets' => 20, 'active' => 1]))->appendToNode($root->refresh())->save();
        (new SoftBranch(['name' => 'b', 'tickets' => 30, 'active' => 1]))->appendToNode($root->refresh())->save();
        $c = new SoftBranch(['name' => 'c', 'tickets' => 40, 'active' => 1]);
        $c->appendToNode($root->refresh())->save();

        // Soft-delete the largest leaf. The quantile subquery's soft clause
        // must drop it from the inclusive set.
        $c->refresh()->delete();

        $fresh = SoftBranch::query()
            ->withFreshAggregates(['m' => Aggregate::median('tickets')])
            ->where('id', $root->id)
            ->firstOrFail();

        // Live set {10, 20, 30} → 20. Including trashed {10,20,30,40} → 25.
        $this->assertEqualsWithDelta(20.0, $this->asFloat($fresh->getAttribute('m')), 0.0001);
    }

    #[Test]
    public function quantile_fresh_read_applies_a_filter_predicate(): void
    {
        TypedArea::query()->getConnection()->table('typed_areas')->insert([
            ['id' => 1, 'name' => 'Root',   'tickets' => 10, 'type' => 'fire',  'lft' => 1, 'rgt' => 8, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'F1',     'tickets' => 20, 'type' => 'fire',  'lft' => 2, 'rgt' => 3, 'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'W1',     'tickets' => 999, 'type' => 'water', 'lft' => 4, 'rgt' => 5, 'depth' => 1, 'parent_id' => 1],
            ['id' => 4, 'name' => 'F2',     'tickets' => 30, 'type' => 'fire',  'lft' => 6, 'rgt' => 7, 'depth' => 1, 'parent_id' => 1],
        ]);
        $this->syncSequence('typed_areas');

        $root = TypedArea::query()
            ->withFreshAggregates(['m' => Aggregate::median('tickets')->filter(['type' => 'fire'])])
            ->where('id', 1)
            ->firstOrFail();

        // Filtered to fire only: {10, 20, 30} → 20. The water row (999)
        // must be excluded by the filter predicate, not just the bounds.
        $this->assertEqualsWithDelta(20.0, $this->asFloat($root->getAttribute('m')), 0.0001);
    }

    #[Test]
    public function with_fresh_aggregates_is_a_noop_for_a_listener_only_model(): void
    {
        $root = new Monster(['name' => 'Root', 'type' => 'water', 'base_power' => 2, 'level' => 5]);
        $root->saveAsRoot();
        (new Monster(['name' => 'Child', 'type' => 'fire', 'base_power' => 10, 'level' => 3]))
            ->appendToNode($root->refresh())->save();

        // Monster declares only listener aggregates (no SQL aggregate
        // columns), so withFreshAggregates() resolves to an empty set,
        // skips every listener definition, and adds no fresh selects.
        $names = Monster::query()
            ->withFreshAggregates()
            ->orderBy('lft')
            ->get()
            ->map(fn (Monster $m): string => $m->name)
            ->all();

        $this->assertSame(['Root', 'Child'], $names);
    }
}
