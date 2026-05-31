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

    public function test_create_invalidates_lazy_columns_on_ancestor_chain(): void
    {
        $this->buildTree();

        $rootRaw = $this->rawRow($this->ids['Root']);
        $this->assertSame(100, $this->asInt($rootRaw['tickets_total']));

        foreach (['Root', 'A', 'A1', 'B'] as $name) {
            $raw = $this->rawRow($this->ids[$name]);
            $this->assertNull($raw['lazy_tickets_total'], "{$name}.lazy_tickets_total should start NULL");
            $this->assertNull($raw['lazy_tickets_total_computed_at'], "{$name} stamp should start NULL");
            $this->assertNull($raw['lazy_tickets_count'], "{$name}.lazy_tickets_count should start NULL");
            $this->assertNull($raw['lazy_listener_sum'], "{$name}.lazy_listener_sum should start NULL");
        }
    }

    public function test_read_of_stale_lazy_column_recomputes_and_stamps(): void
    {
        $this->buildTree();

        $root = $this->freshModel($this->ids['Root']);
        $this->assertSame(100, (int) $root->lazy_tickets_total);

        $rootRaw = $this->rawRow($this->ids['Root']);
        $this->assertSame(100, $this->asInt($rootRaw['lazy_tickets_total']));
        $this->assertNotNull($rootRaw['lazy_tickets_total_computed_at']);
    }

    public function test_read_of_fresh_lazy_column_does_not_re_recompute(): void
    {
        $this->buildTree();

        $root = $this->freshModel($this->ids['Root']);

        $this->assertSame(100, (int) $root->lazy_tickets_total);
        $stampAfterFirst = $this->rawRow($this->ids['Root'])['lazy_tickets_total_computed_at'];
        $this->assertNotNull($stampAfterFirst);

        $this->assertSame(100, (int) $root->lazy_tickets_total);
        $stampAfterSecond = $this->rawRow($this->ids['Root'])['lazy_tickets_total_computed_at'];
        $this->assertSame($stampAfterFirst, $stampAfterSecond);
    }

    public function test_read_recomputes_listener_lazy_aggregate(): void
    {
        $this->buildTree();

        // DoubleTicketsListener returns 2x tickets — distinguishes the
        // listener-side recompute from the SQL Sum over the same source.
        $root = $this->freshModel($this->ids['Root']);
        $this->assertSame(200, (int) $root->lazy_listener_sum);

        $rootRaw = $this->rawRow($this->ids['Root']);
        $this->assertSame(200, $this->asInt($rootRaw['lazy_listener_sum']));
        $this->assertNotNull($rootRaw['lazy_listener_sum_computed_at']);
    }

    public function test_source_change_invalidates_lazy_columns_on_chain(): void
    {
        $this->buildTree();

        $root = $this->freshModel($this->ids['Root']);
        $this->assertSame(100, (int) $root->lazy_tickets_total);
        $this->assertSame(4, (int) $root->lazy_tickets_count);
        $rootRaw = $this->rawRow($this->ids['Root']);
        $this->assertNotNull($rootRaw['lazy_tickets_total_computed_at']);
        $this->assertNotNull($rootRaw['lazy_tickets_count_computed_at']);

        $a1 = $this->freshModel($this->ids['A1']);
        $a1->tickets = 999;
        $a1->save();

        $rootAfter = $this->rawRow($this->ids['Root']);
        $this->assertNull($rootAfter['lazy_tickets_total']);
        $this->assertNull($rootAfter['lazy_tickets_total_computed_at']);
        // COUNT(*) has no source column — `tickets` is not in its trigger
        // set, so the source mutation does not invalidate this aggregate.
        $this->assertNotNull($rootAfter['lazy_tickets_count_computed_at']);

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

        // `name` is not a trigger column for lazy_tickets_total, so this
        // save must not invalidate the warm stamp on Root.
        $a = $this->freshModel($this->ids['A']);
        $a->name = 'A renamed';
        $a->save();

        $stampAfter = $this->rawRow($this->ids['Root'])['lazy_tickets_total_computed_at'];
        $this->assertSame($stampBefore, $stampAfter);
    }

    public function test_delete_invalidates_ancestor_lazy_columns(): void
    {
        $this->buildTree();

        $root = $this->freshModel($this->ids['Root']);
        $this->assertSame(100, (int) $root->lazy_tickets_total);
        $this->assertNotNull($this->rawRow($this->ids['Root'])['lazy_tickets_total_computed_at']);

        $a1 = $this->freshModel($this->ids['A1']);
        $a1->delete();

        $this->assertNull($this->rawRow($this->ids['Root'])['lazy_tickets_total_computed_at']);

        $root2 = $this->freshModel($this->ids['Root']);
        $this->assertSame(10 + 20 + 40, (int) $root2->lazy_tickets_total);
    }

    public function test_create_descendant_invalidates_ancestor_lazy_columns(): void
    {
        $this->buildTree();

        $root = $this->freshModel($this->ids['Root']);
        $this->assertSame(100, (int) $root->lazy_tickets_total);
        $this->assertNotNull($this->rawRow($this->ids['Root'])['lazy_tickets_total_computed_at']);

        $a2 = new LazyArea(['name' => 'A2', 'tickets' => 7]);
        $a2->appendToNode($this->freshModel($this->ids['A1']))->save();

        $this->assertNull($this->rawRow($this->ids['Root'])['lazy_tickets_total_computed_at']);

        $root2 = $this->freshModel($this->ids['Root']);
        $this->assertSame(10 + 20 + 30 + 40 + 7, (int) $root2->lazy_tickets_total);
    }

    public function test_exclusive_lazy_descendants_total_excludes_self(): void
    {
        $this->buildTree();

        $root = $this->freshModel($this->ids['Root']);
        // Descendants-only of Root: 20 + 30 + 40 = 90 (Root's own 10 excluded).
        $this->assertSame(90, (int) $root->lazy_descendants_total);

        $leaf = $this->freshModel($this->ids['A1']);
        $this->assertSame(0, (int) $leaf->lazy_descendants_total);
    }

    public function test_move_invalidates_both_old_and_new_chain(): void
    {
        $this->buildTree();

        // Warm both ends so we can prove invalidation reached each one.
        $this->assertSame(100, (int) $this->freshModel($this->ids['Root'])->lazy_tickets_total);
        $this->assertSame(40, (int) $this->freshModel($this->ids['B'])->lazy_tickets_total);
        $this->assertNotNull($this->rawRow($this->ids['Root'])['lazy_tickets_total_computed_at']);
        $this->assertNotNull($this->rawRow($this->ids['B'])['lazy_tickets_total_computed_at']);

        // Move A1 from under A to under B. The OLD chain (A, Root) lost
        // A1's contribution; the NEW chain (B, Root) gained it. Both must
        // be invalidated — a single-side invalidation would leave one
        // chain stale until the next mutation.
        $a1 = $this->freshModel($this->ids['A1']);
        $b = $this->freshModel($this->ids['B']);
        $a1->appendToNode($b)->save();

        $this->assertNull($this->rawRow($this->ids['Root'])['lazy_tickets_total_computed_at']);
        $this->assertNull($this->rawRow($this->ids['B'])['lazy_tickets_total_computed_at']);
    }

    public function test_fix_aggregates_writes_value_and_stamp_for_lazy_columns(): void
    {
        $this->buildTree();

        foreach (['Root', 'A', 'B'] as $name) {
            $raw = $this->rawRow($this->ids[$name]);
            $this->assertNull($raw['lazy_tickets_total']);
            $this->assertNull($raw['lazy_tickets_total_computed_at']);
        }

        LazyArea::fixAggregates();

        // The stamp pass is what distinguishes lazy fixAggregates from
        // its eager counterpart — without it, every row would read NULL
        // and immediately re-recompute the value the differ just wrote.
        $rootRaw = $this->rawRow($this->ids['Root']);
        $this->assertSame(100, $this->asInt($rootRaw['lazy_tickets_total']));
        $this->assertNotNull($rootRaw['lazy_tickets_total_computed_at']);

        $a1Raw = $this->rawRow($this->ids['A1']);
        $this->assertSame(30, $this->asInt($a1Raw['lazy_tickets_total']));
        $this->assertNotNull($a1Raw['lazy_tickets_total_computed_at']);

        $this->assertSame(200, $this->asInt($rootRaw['lazy_listener_sum']));
        $this->assertNotNull($rootRaw['lazy_listener_sum_computed_at']);
    }

    public function test_ttl_expired_triggers_recompute_on_next_read(): void
    {
        $this->buildTree();

        $root = $this->freshModel($this->ids['Root']);
        $this->assertSame(100, (int) $root->lazy_tickets_total_ttl);
        $stampBefore = $this->rawRow($this->ids['Root'])['lazy_tickets_total_ttl_computed_at'];
        $this->assertNotNull($stampBefore);

        // Backdate past the model's 60s TTL — no mutation occurs, so
        // the next read must recompute on time alone.
        $backdated = date('Y-m-d H:i:s', time() - 120);
        LazyArea::query()->getConnection()
            ->table('lazy_areas')
            ->where('id', $this->ids['Root'])
            ->update([
                'lazy_tickets_total_ttl_computed_at' => $backdated,
            ]);

        $root2 = $this->freshModel($this->ids['Root']);
        $this->assertSame(100, (int) $root2->lazy_tickets_total_ttl);
        $stampAfter = $this->rawRow($this->ids['Root'])['lazy_tickets_total_ttl_computed_at'];
        $this->assertNotSame($backdated, $stampAfter);
        $this->assertNotNull($stampAfter);
    }
}
