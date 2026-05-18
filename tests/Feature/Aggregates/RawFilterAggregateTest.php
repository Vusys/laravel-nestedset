<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use Vusys\NestedSet\Aggregates\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\Branch;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Coverage for FilterPredicate::raw — the maintenance pipeline cannot
 * evaluate raw SQL predicates in PHP, so it skips the relevant columns
 * on save() and relies on fixAggregates() / fresh-read for recovery.
 *
 * Branch declares `active_tickets_total` with `filterRaw: 'active = 1'`.
 * The bare `active` reference is unambiguous because the package emits
 * raw-filtered aggregates as correlated subqueries whose only `FROM`
 * is the model's table — SQL's local resolution rule pins bare names
 * to the inner row.
 *
 *  Root(tickets=10, active=true)
 *  ├── A(tickets=20, active=false)   ← inactive: should NOT contribute
 *  └── B(tickets=40, active=true)
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

    public function test_raw_filter_column_is_skipped_during_incremental_maintenance(): void
    {
        $this->buildTree();

        // Save events ran but the raw-filter column was never written,
        // so the row defaults to 0 even though A is inactive and B+Root
        // are active.
        $root = $this->rowById('branches', $this->ids['Root']);
        $this->assertSame(0, (int) $root->active_tickets_total);

        // Inclusive baseline maintained as expected (10+20+40 = 70).
        $this->assertSame(70, (int) $root->tickets_total);
    }

    public function test_fix_aggregates_recovers_raw_filter_column(): void
    {
        $this->buildTree();

        Branch::fixAggregates();

        $root = $this->rowById('branches', $this->ids['Root']);
        $a = $this->rowById('branches', $this->ids['A']);
        $b = $this->rowById('branches', $this->ids['B']);

        // Root: 10 (self, active) + 40 (B, active) = 50.  A is excluded.
        $this->assertSame(50, (int) $root->active_tickets_total);
        // A (leaf, inactive): 0 — even self is filtered out.
        $this->assertSame(0, (int) $a->active_tickets_total);
        // B (leaf, active): 40.
        $this->assertSame(40, (int) $b->active_tickets_total);
    }

    public function test_aggregate_errors_flags_raw_filter_drift(): void
    {
        $this->buildTree();

        $errors = Branch::aggregateErrors();

        // Stored is 0 across the board, fresh would compute non-zero
        // for Root and B → 2 rows of drift on active_tickets_total.
        $this->assertGreaterThanOrEqual(2, $errors['active_tickets_total'] ?? 0);
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

    public function test_updating_active_does_not_propagate_to_raw_filter_column(): void
    {
        $this->buildTree();

        // Toggle A's active flag — a watched column for the raw filter,
        // but incremental maintenance still skips because the predicate
        // is raw SQL with no PHP evaluator.
        $a = Branch::query()->findOrFail($this->ids['A']);
        $a->active = 1;
        $a->save();

        // Inclusive tickets_total unaffected (active doesn't influence it).
        // But active_tickets_total is still 0 on root — incremental skip
        // confirmed.
        $root = $this->rowById('branches', $this->ids['Root']);
        $this->assertSame(0, (int) $root->active_tickets_total);

        // Now fixAggregates picks up the new state: A active = true means
        // it contributes 20 → root.active_tickets_total = 10 + 20 + 40 = 70.
        Branch::fixAggregates();
        $root = $this->rowById('branches', $this->ids['Root']);
        $this->assertSame(70, (int) $root->active_tickets_total);
    }
}
