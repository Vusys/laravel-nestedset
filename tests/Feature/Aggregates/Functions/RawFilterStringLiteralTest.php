<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Functions;

use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Query\Aggregates\Sql\FragmentSplicer;
use Vusys\NestedSet\Tests\Fixtures\Models\StatusBranch;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Regression coverage for raw-filter predicates that embed single-quoted
 * string literals — the most common real-world filter shape outside
 * `column = N`. Pins that the literals round-trip cleanly through
 * {@see FragmentSplicer}'s
 * sentinel-replacement / parameter-binding stream on every supported
 * backend (sqlite / mysql / mariadb / pgsql).
 *
 * `RawFilterAggregateTest` already covers the `active = 1` shape;
 * this file is specifically about the quoting path. The two
 * complementary aggregate functions (Sum + Count) sweep the inline
 * raw-filter SQL emitter's match-arm coverage for the IN-list literal
 * filter.
 *
 *  Root(points=10, status='open')
 *  ├── A(points=20, status='archived')   ← filtered out
 *  ├── B(points=40, status='closed')
 *  └── C(points=80, status='pending')    ← filtered out
 *
 *  open_or_closed_points_total at Root = 10 + 40 = 50
 *  open_or_closed_count        at Root = 2 (Root + B)
 */
final class RawFilterStringLiteralTest extends TestCase
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
        $root = new StatusBranch(['name' => 'Root', 'points' => 10, 'status' => 'open']);
        $root->saveAsRoot();

        $a = new StatusBranch(['name' => 'A', 'points' => 20, 'status' => 'archived']);
        $a->appendToNode($root)->save();

        $b = new StatusBranch(['name' => 'B', 'points' => 40, 'status' => 'closed']);
        $b->appendToNode($root->refresh())->save();

        $c = new StatusBranch(['name' => 'C', 'points' => 80, 'status' => 'pending']);
        $c->appendToNode($root->refresh())->save();

        $this->ids = [
            'Root' => (int) $root->id,
            'A' => (int) $a->id,
            'B' => (int) $b->id,
            'C' => (int) $c->id,
        ];
    }

    public function test_maintained_value_matches_hand_computed_total_at_root(): void
    {
        $this->buildTree();

        $root = $this->rowById('status_branches', $this->ids['Root']);

        // Root + B match the IN-list; A and C don't.
        $this->assertSame(50, (int) $root->open_or_closed_points_total);
        $this->assertSame(2, (int) $root->open_or_closed_count);

        // Baseline inclusive SUM stays correct (10 + 20 + 40 + 80 = 150).
        $this->assertSame(150, (int) $root->points_total);
    }

    public function test_maintained_value_matches_fresh_recompute(): void
    {
        $this->buildTree();

        $fresh = StatusBranch::query()
            ->withFreshAggregates([
                'open_or_closed_points_total',
                'open_or_closed_count',
                'points_total',
            ])
            ->where('id', $this->ids['Root'])
            ->firstOrFail();

        $stored = $this->rowById('status_branches', $this->ids['Root']);

        // If sentinel replacement mangled the single-quoted literals
        // somewhere along the stored-vs-fresh paths, the two would
        // disagree — either fresh would scan a different row set or
        // stored maintenance would have computed against a different
        // predicate at write time.
        $this->assertSame(
            (int) $stored->open_or_closed_points_total,
            (int) $fresh->open_or_closed_points_total,
        );
        $this->assertSame(
            (int) $stored->open_or_closed_count,
            (int) $fresh->open_or_closed_count,
        );
        $this->assertSame((int) $stored->points_total, (int) $fresh->points_total);
    }

    public function test_aggregate_errors_returns_zero_for_string_literal_filter(): void
    {
        $this->buildTree();

        $errors = StatusBranch::aggregateErrors();

        // Drift detection runs through the SAME SQL emitter as the
        // maintained write path; any quoting-stream divergence between
        // them would surface here as a non-zero error count even when
        // the stored values are internally self-consistent.
        $this->assertSame(0, $errors['open_or_closed_points_total'] ?? -1);
        $this->assertSame(0, $errors['open_or_closed_count'] ?? -1);
    }

    public function test_flipping_status_into_filter_updates_aggregate(): void
    {
        $this->buildTree();

        // Pre-edit: 50.
        $root = $this->rowById('status_branches', $this->ids['Root']);
        $this->assertSame(50, (int) $root->open_or_closed_points_total);

        // Move A into the filter set.
        $a = StatusBranch::query()->findOrFail($this->ids['A']);
        $a->status = 'open';
        $a->save();

        $root = $this->rowById('status_branches', $this->ids['Root']);

        // Root + A + B = 10 + 20 + 40 = 70.
        $this->assertSame(70, (int) $root->open_or_closed_points_total);
        $this->assertSame(3, (int) $root->open_or_closed_count);
    }

    public function test_flipping_status_out_of_filter_updates_aggregate(): void
    {
        $this->buildTree();

        // Move B out of the filter set.
        $b = StatusBranch::query()->findOrFail($this->ids['B']);
        $b->status = 'archived';
        $b->save();

        $root = $this->rowById('status_branches', $this->ids['Root']);

        // Only Root remains: 10 / 1.
        $this->assertSame(10, (int) $root->open_or_closed_points_total);
        $this->assertSame(1, (int) $root->open_or_closed_count);
    }

    public function test_fresh_read_applies_string_literal_filter(): void
    {
        $this->buildTree();

        // Direct withFreshAggregates round-trip — exercises the read-path
        // SQL emitter independent of the maintained columns. A failure
        // here points at FreshAggregateProjector / fragment splicing
        // rather than at the lifecycle hooks.
        $root = StatusBranch::query()
            ->withFreshAggregates([
                'open_or_closed_points_total',
                'open_or_closed_count',
            ])
            ->where('id', $this->ids['Root'])
            ->firstOrFail();

        $this->assertSame(50, (int) $root->open_or_closed_points_total);
        $this->assertSame(2, (int) $root->open_or_closed_count);
    }
}
