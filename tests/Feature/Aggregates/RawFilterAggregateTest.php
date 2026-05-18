<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use Vusys\NestedSet\Aggregates\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\Branch;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Coverage for FilterPredicate::raw — raw SQL predicates can't be
 * evaluated in PHP, so the maintenance pipeline can't produce a
 * signed delta. Instead, when any watched column changes (or the row
 * is created / deleted / moved / restored), the package bulk-recomputes
 * the affected raw-filter column over the affected ancestor chain.
 * One extra SELECT + per-ancestor UPDATE per save when raw filters
 * are dirty; otherwise no extra cost.
 *
 * Branch declares `active_tickets_total` with `filterRaw: 'active = 1'`.
 * Bare `active` is unambiguous because the package emits raw-filtered
 * aggregates as correlated subqueries whose only `FROM` is the model's
 * table — SQL's local resolution rule pins bare names to the inner row.
 *
 *  Root(tickets=10, active=1)
 *  ├── A(tickets=20, active=0)   ← inactive: should NOT contribute
 *  └── B(tickets=40, active=1)
 */
final class RawFilterAggregateTest extends TestCase
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
        $root = new Branch(['name' => 'Root', 'tickets' => 10, 'active' => 1]);
        $root->saveAsRoot();

        $a = new Branch(['name' => 'A', 'tickets' => 20, 'active' => 0]);
        $a->appendToNode($root)->save();

        $b = new Branch(['name' => 'B', 'tickets' => 40, 'active' => 1]);
        $b->appendToNode($root)->save();

        $this->ids = [
            'Root' => (int) $root->id,
            'A' => (int) $a->id,
            'B' => (int) $b->id,
        ];
    }

    public function test_raw_filter_column_maintained_on_create(): void
    {
        $this->buildTree();

        $root = $this->rowById('branches', $this->ids['Root']);
        $a = $this->rowById('branches', $this->ids['A']);
        $b = $this->rowById('branches', $this->ids['B']);

        // Root: 10 (self, active) + 40 (B, active) = 50. A is excluded.
        $this->assertSame(50, (int) $root->active_tickets_total);
        // A (inactive leaf): 0 — even self is filtered out.
        $this->assertSame(0, (int) $a->active_tickets_total);
        // B (active leaf): 40.
        $this->assertSame(40, (int) $b->active_tickets_total);

        // Inclusive baseline maintained as expected (10+20+40 = 70).
        $this->assertSame(70, (int) $root->tickets_total);
    }

    public function test_no_drift_after_create(): void
    {
        $this->buildTree();

        $errors = Branch::aggregateErrors();

        $this->assertSame(0, $errors['active_tickets_total'] ?? -1);
    }

    public function test_fresh_read_applies_raw_filter(): void
    {
        $this->buildTree();

        $root = Branch::query()
            ->withFreshAggregates(['active_tickets_total'])
            ->where('id', $this->ids['Root'])
            ->firstOrFail();

        $this->assertSame(50, (int) $root->active_tickets_total);
    }

    public function test_updating_watch_column_propagates_to_raw_filter(): void
    {
        $this->buildTree();

        // Before: root's active_tickets_total = 50 (Root + B, both active).
        $root = $this->rowById('branches', $this->ids['Root']);
        $this->assertSame(50, (int) $root->active_tickets_total);

        // Toggle A's active flag on — A now contributes 20.
        $a = Branch::query()->findOrFail($this->ids['A']);
        $a->active = 1;
        $a->save();

        $root = $this->rowById('branches', $this->ids['Root']);
        $this->assertSame(70, (int) $root->active_tickets_total);

        // Toggle B off — root drops to Root(10) + A(20) = 30.
        $b = Branch::query()->findOrFail($this->ids['B']);
        $b->active = 0;
        $b->save();

        $root = $this->rowById('branches', $this->ids['Root']);
        $this->assertSame(30, (int) $root->active_tickets_total);
    }

    public function test_updating_source_column_propagates_to_raw_filter(): void
    {
        $this->buildTree();

        // Bump B's tickets: active=1 so it contributes via the filter.
        $b = Branch::query()->findOrFail($this->ids['B']);
        $b->tickets = 100;
        $b->save();

        $root = $this->rowById('branches', $this->ids['Root']);
        // Root(10) + B(100) = 110.
        $this->assertSame(110, (int) $root->active_tickets_total);
    }

    public function test_deleting_active_row_propagates_to_raw_filter(): void
    {
        $this->buildTree();

        $b = Branch::query()->findOrFail($this->ids['B']);
        $b->delete();

        $root = $this->rowById('branches', $this->ids['Root']);
        // Only Root(10) remains active in the subtree.
        $this->assertSame(10, (int) $root->active_tickets_total);
    }

    public function test_unrelated_save_does_not_recompute_raw_filter(): void
    {
        $this->buildTree();

        // Change the `name` column — not in the raw filter's watch list
        // and not the source column. The chain recompute should be skipped.
        $a = Branch::query()->findOrFail($this->ids['A']);
        $a->name = 'A-renamed';
        $a->save();

        $root = $this->rowById('branches', $this->ids['Root']);
        // Unchanged from pre-edit value.
        $this->assertSame(50, (int) $root->active_tickets_total);
    }
}
