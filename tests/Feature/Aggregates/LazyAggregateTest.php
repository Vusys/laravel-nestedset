<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\LazyArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * End-to-end coverage of the lazy-aggregate maintenance shape: every
 * lifecycle mutation invalidates value + stamp on the affected
 * ancestor chain, and the first read past the invalidation runs
 * `freshAggregate()` then stamps `<column>_computed_at`.
 *
 * Tree shape used by most tests:
 *
 *   Root(tickets=10)
 *   ├── A(tickets=20)
 *   │   └── A1(tickets=30)
 *   └── B(tickets=40)
 */
final class LazyAggregateTest extends TestCase
{
    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    private function buildTree(): void
    {
        $root = new LazyArea(['name' => 'Root', 'tickets' => 10]);
        $root->saveAsRoot();

        $a = new LazyArea(['name' => 'A', 'tickets' => 20]);
        $a->appendToNode($root)->save();

        $a1 = new LazyArea(['name' => 'A1', 'tickets' => 30]);
        $a1->appendToNode($a)->save();

        $b = new LazyArea(['name' => 'B', 'tickets' => 40]);
        $b->appendToNode($root)->save();

        $this->ids = [
            'Root' => (int) $root->id,
            'A' => (int) $a->id,
            'A1' => (int) $a1->id,
            'B' => (int) $b->id,
        ];
    }

    /**
     * Reads the raw row bypassing Eloquent so the read accessor's lazy
     * refresh does not fire. Used to assert the stored state directly.
     *
     * @return array<string, mixed>
     */
    private function rawRow(int $id): array
    {
        $row = LazyArea::query()->getConnection()
            ->table('lazy_areas')
            ->where('id', $id)
            ->first();

        return $row === null ? [] : (array) $row;
    }

    private function freshModel(int $id): LazyArea
    {
        return LazyArea::query()->findOrFail($id);
    }

    private function asInt(mixed $value): int
    {
        if (! is_numeric($value)) {
            $this->fail('Expected numeric value, got '.get_debug_type($value));
        }

        return (int) $value;
    }

    // ----------------------------------------------------------------
    // Initial create: lazy columns stay NULL; eager control populates.
    // ----------------------------------------------------------------

    public function test_create_invalidates_lazy_columns_on_ancestor_chain(): void
    {
        $this->buildTree();

        // The eager control column was populated by delta maintenance.
        $rootRaw = $this->rawRow($this->ids['Root']);
        $this->assertSame(100, $this->asInt($rootRaw['tickets_total']));

        // Every lazy column on every node is NULL after the build.
        foreach (['Root', 'A', 'A1', 'B'] as $name) {
            $raw = $this->rawRow($this->ids[$name]);
            $this->assertNull($raw['lazy_tickets_total'], "{$name}.lazy_tickets_total should start NULL");
            $this->assertNull($raw['lazy_tickets_total_computed_at'], "{$name} stamp should start NULL");
            $this->assertNull($raw['lazy_tickets_count'], "{$name}.lazy_tickets_count should start NULL");
            $this->assertNull($raw['lazy_listener_sum'], "{$name}.lazy_listener_sum should start NULL");
        }
    }

    // ----------------------------------------------------------------
    // Read accessor: stale value triggers recompute + stamp.
    // ----------------------------------------------------------------

    public function test_read_of_stale_lazy_column_recomputes_and_stamps(): void
    {
        $this->buildTree();

        $root = $this->freshModel($this->ids['Root']);

        // Reading the lazy column triggers freshAggregate + writeback.
        $this->assertSame(100, (int) $root->lazy_tickets_total);

        // The stored row now reflects the computed value AND a stamp.
        $rootRaw = $this->rawRow($this->ids['Root']);
        $this->assertSame(100, $this->asInt($rootRaw['lazy_tickets_total']));
        $this->assertNotNull($rootRaw['lazy_tickets_total_computed_at']);
    }

    public function test_read_of_fresh_lazy_column_does_not_re_recompute(): void
    {
        $this->buildTree();

        $root = $this->freshModel($this->ids['Root']);

        // First read populates value + stamp.
        $this->assertSame(100, (int) $root->lazy_tickets_total);
        $stampAfterFirst = $this->rawRow($this->ids['Root'])['lazy_tickets_total_computed_at'];
        $this->assertNotNull($stampAfterFirst);

        // Re-read on the same Eloquent instance must not bump the
        // stamp — the in-memory attribute is already populated and
        // the stamp companion is non-NULL.
        $this->assertSame(100, (int) $root->lazy_tickets_total);
        $stampAfterSecond = $this->rawRow($this->ids['Root'])['lazy_tickets_total_computed_at'];
        $this->assertSame($stampAfterFirst, $stampAfterSecond);
    }

    public function test_read_recomputes_listener_lazy_aggregate(): void
    {
        $this->buildTree();

        // DoubleTicketsListener returns 2x tickets. Root subtree sum is 200.
        $root = $this->freshModel($this->ids['Root']);
        $this->assertSame(200, (int) $root->lazy_listener_sum);

        $rootRaw = $this->rawRow($this->ids['Root']);
        $this->assertSame(200, $this->asInt($rootRaw['lazy_listener_sum']));
        $this->assertNotNull($rootRaw['lazy_listener_sum_computed_at']);
    }

    // ----------------------------------------------------------------
    // Save-time invalidation: source-column dirty restages stale rows.
    // ----------------------------------------------------------------

    public function test_source_change_invalidates_lazy_columns_on_chain(): void
    {
        $this->buildTree();

        // Warm both lazy columns at Root.
        $root = $this->freshModel($this->ids['Root']);
        $this->assertSame(100, (int) $root->lazy_tickets_total);
        $this->assertSame(4, (int) $root->lazy_tickets_count);
        $rootRaw = $this->rawRow($this->ids['Root']);
        $this->assertNotNull($rootRaw['lazy_tickets_total_computed_at']);
        $this->assertNotNull($rootRaw['lazy_tickets_count_computed_at']);

        // Mutate A1's source column.
        $a1 = $this->freshModel($this->ids['A1']);
        $a1->tickets = 999;
        $a1->save();

        // Ancestors lose their stamp.
        $rootAfter = $this->rawRow($this->ids['Root']);
        $this->assertNull($rootAfter['lazy_tickets_total']);
        $this->assertNull($rootAfter['lazy_tickets_total_computed_at']);
        // Count was warm too; tickets change shouldn't affect count
        // semantically — but the invalidation captures any aggregate
        // whose watch columns include the dirty column. COUNT(*) has
        // no source column, so lazy_tickets_count stays warm.
        $this->assertNotNull($rootAfter['lazy_tickets_count_computed_at']);

        // Next read picks up the new value.
        $root2 = $this->freshModel($this->ids['Root']);
        $this->assertSame(10 + 20 + 999 + 40, (int) $root2->lazy_tickets_total);
    }

    public function test_unrelated_column_change_does_not_invalidate(): void
    {
        $this->buildTree();

        $root = $this->freshModel($this->ids['Root']);
        $this->assertSame(100, (int) $root->lazy_tickets_total);
        $stampBefore = $this->rawRow($this->ids['Root'])['lazy_tickets_total_computed_at'];
        $this->assertNotNull($stampBefore);

        // Change A's `name` — not a triggerColumn for lazy_tickets_total.
        $a = $this->freshModel($this->ids['A']);
        $a->name = 'A renamed';
        $a->save();

        $stampAfter = $this->rawRow($this->ids['Root'])['lazy_tickets_total_computed_at'];
        $this->assertSame($stampBefore, $stampAfter);
    }

    // ----------------------------------------------------------------
    // Create / delete: ancestor invalidation regardless of dirty.
    // ----------------------------------------------------------------

    public function test_delete_invalidates_ancestor_lazy_columns(): void
    {
        $this->buildTree();

        // Warm Root.
        $root = $this->freshModel($this->ids['Root']);
        $this->assertSame(100, (int) $root->lazy_tickets_total);
        $this->assertNotNull($this->rawRow($this->ids['Root'])['lazy_tickets_total_computed_at']);

        // Delete A1.
        $a1 = $this->freshModel($this->ids['A1']);
        $a1->delete();

        // Root's stamp is gone.
        $this->assertNull($this->rawRow($this->ids['Root'])['lazy_tickets_total_computed_at']);

        // Re-read picks up the post-delete total.
        $root2 = $this->freshModel($this->ids['Root']);
        $this->assertSame(10 + 20 + 40, (int) $root2->lazy_tickets_total);
    }

    public function test_create_descendant_invalidates_ancestor_lazy_columns(): void
    {
        $this->buildTree();

        // Warm Root.
        $root = $this->freshModel($this->ids['Root']);
        $this->assertSame(100, (int) $root->lazy_tickets_total);
        $this->assertNotNull($this->rawRow($this->ids['Root'])['lazy_tickets_total_computed_at']);

        // Add a new child under A1.
        $a2 = new LazyArea(['name' => 'A2', 'tickets' => 7]);
        $a2->appendToNode($this->freshModel($this->ids['A1']))->save();

        $this->assertNull($this->rawRow($this->ids['Root'])['lazy_tickets_total_computed_at']);

        $root2 = $this->freshModel($this->ids['Root']);
        $this->assertSame(10 + 20 + 30 + 40 + 7, (int) $root2->lazy_tickets_total);
    }

    // ----------------------------------------------------------------
    // Exclusive lazy semantics.
    // ----------------------------------------------------------------

    public function test_exclusive_lazy_descendants_total_excludes_self(): void
    {
        $this->buildTree();

        $root = $this->freshModel($this->ids['Root']);
        // Descendants-only of Root: 20 + 30 + 40 = 90 (excludes self's 10).
        $this->assertSame(90, (int) $root->lazy_descendants_total);

        $leaf = $this->freshModel($this->ids['A1']);
        // Leaf descendants: 0.
        $this->assertSame(0, (int) $leaf->lazy_descendants_total);
    }

    // ----------------------------------------------------------------
    // Move: both old and new ancestor chains invalidated.
    // ----------------------------------------------------------------

    public function test_move_invalidates_both_old_and_new_chain(): void
    {
        $this->buildTree();

        // Warm Root and B with the lazy column. (B is a leaf — its
        // inclusive lazy sum equals its own tickets.)
        $this->assertSame(100, (int) $this->freshModel($this->ids['Root'])->lazy_tickets_total);
        $this->assertSame(40, (int) $this->freshModel($this->ids['B'])->lazy_tickets_total);
        $this->assertNotNull($this->rawRow($this->ids['Root'])['lazy_tickets_total_computed_at']);
        $this->assertNotNull($this->rawRow($this->ids['B'])['lazy_tickets_total_computed_at']);

        // Move A1 (under A → under B). Both the old chain (A, Root)
        // and the new chain (B, Root) lose their stamps.
        $a1 = $this->freshModel($this->ids['A1']);
        $b = $this->freshModel($this->ids['B']);
        $a1->appendToNode($b)->save();

        $this->assertNull($this->rawRow($this->ids['Root'])['lazy_tickets_total_computed_at']);
        $this->assertNull($this->rawRow($this->ids['B'])['lazy_tickets_total_computed_at']);

        // A's stamp may or may not be present depending on warm-up; we
        // only assert that the move's invalidation reached the OLD
        // ancestor — Root — and the NEW ancestor — B.
    }

    // ----------------------------------------------------------------
    // fixAggregates(): writes both value and stamp.
    // ----------------------------------------------------------------

    public function test_fix_aggregates_writes_value_and_stamp_for_lazy_columns(): void
    {
        $this->buildTree();

        // Pre-state: lazy columns NULL.
        foreach (['Root', 'A', 'B'] as $name) {
            $raw = $this->rawRow($this->ids[$name]);
            $this->assertNull($raw['lazy_tickets_total']);
            $this->assertNull($raw['lazy_tickets_total_computed_at']);
        }

        LazyArea::fixAggregates();

        // Post-fix: every row has value + stamp.
        $rootRaw = $this->rawRow($this->ids['Root']);
        $this->assertSame(100, $this->asInt($rootRaw['lazy_tickets_total']));
        $this->assertNotNull($rootRaw['lazy_tickets_total_computed_at']);

        $a1Raw = $this->rawRow($this->ids['A1']);
        $this->assertSame(30, $this->asInt($a1Raw['lazy_tickets_total']));
        $this->assertNotNull($a1Raw['lazy_tickets_total_computed_at']);

        // Listener lazy column likewise.
        $this->assertSame(200, $this->asInt($rootRaw['lazy_listener_sum']));
        $this->assertNotNull($rootRaw['lazy_listener_sum_computed_at']);
    }

    // ----------------------------------------------------------------
    // TTL: expired stamp triggers refresh on next read.
    // ----------------------------------------------------------------

    public function test_ttl_expired_triggers_recompute_on_next_read(): void
    {
        $this->buildTree();

        // Warm the TTL column.
        $root = $this->freshModel($this->ids['Root']);
        $this->assertSame(100, (int) $root->lazy_tickets_total_ttl);
        $stampBefore = $this->rawRow($this->ids['Root'])['lazy_tickets_total_ttl_computed_at'];
        $this->assertNotNull($stampBefore);

        // Backdate the stamp past the 60s TTL.
        $backdated = date('Y-m-d H:i:s', time() - 120);
        LazyArea::query()->getConnection()
            ->table('lazy_areas')
            ->where('id', $this->ids['Root'])
            ->update([
                'lazy_tickets_total_ttl_computed_at' => $backdated,
            ]);

        // Fresh read should re-stamp.
        $root2 = $this->freshModel($this->ids['Root']);
        $this->assertSame(100, (int) $root2->lazy_tickets_total_ttl);
        $stampAfter = $this->rawRow($this->ids['Root'])['lazy_tickets_total_ttl_computed_at'];
        $this->assertNotSame($backdated, $stampAfter);
        $this->assertNotNull($stampAfter);
    }
}
