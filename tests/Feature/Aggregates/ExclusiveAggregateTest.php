<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\Branch;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Exclusive aggregates exclude self from the rollup — a leaf's
 * exclusive value is always the zero/null element. Delta maintenance
 * currently skips exclusive declarations on save (see
 * HasNestedSetAggregates::applyAggregateOnCreate / Delete / Restore
 * comments — "Exclusive support arrives in Phase G"); fresh-read paths
 * and fixAggregates() handle them correctly. These tests pin both
 * behaviours so a future change to incremental exclusive maintenance
 * has a regression net.
 *
 * Tree shape used throughout:
 *
 *   Root(tickets=10)
 *   ├── A(tickets=20)
 *   │   └── A1(tickets=30)
 *   └── B(tickets=40)
 */
final class ExclusiveAggregateTest extends TestCase
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
        $root = new Branch(['name' => 'Root', 'tickets' => 10]);
        $root->saveAsRoot();

        $a = new Branch(['name' => 'A', 'tickets' => 20]);
        $a->appendToNode($root)->save();

        $a1 = new Branch(['name' => 'A1', 'tickets' => 30]);
        $a1->appendToNode($a)->save();

        $b = new Branch(['name' => 'B', 'tickets' => 40]);
        $b->appendToNode($root)->save();

        $this->ids = [
            'Root' => (int) $root->id,
            'A' => (int) $a->id,
            'A1' => (int) $a1->id,
            'B' => (int) $b->id,
        ];
    }

    /**
     * @param  list<string>|null  $aggregates
     */
    private function freshById(int $id, ?array $aggregates = null): Branch
    {
        return Branch::query()
            ->withFreshAggregates($aggregates)
            ->where('id', $id)
            ->firstOrFail();
    }

    private function asInt(mixed $value): int
    {
        if (! is_numeric($value)) {
            $this->fail('Expected numeric, got '.get_debug_type($value));
        }

        return (int) $value;
    }

    // ----------------------------------------------------------------
    // Fresh-read path: withFreshAggregates() correctly excludes self.
    // ----------------------------------------------------------------

    public function test_fresh_read_excludes_self_for_descendants_total(): void
    {
        $this->buildTree();

        // Root subtree contributions, self excluded: 20+30+40 = 90.
        $this->assertSame(90, (int) $this->freshById($this->ids['Root'], ['descendants_total'])->descendants_total);
        // A subtree contributions, self excluded: 30.
        $this->assertSame(30, (int) $this->freshById($this->ids['A'], ['descendants_total'])->descendants_total);
        // Leaves have no descendants: 0.
        $this->assertSame(0, (int) $this->freshById($this->ids['A1'], ['descendants_total'])->descendants_total);
        $this->assertSame(0, (int) $this->freshById($this->ids['B'], ['descendants_total'])->descendants_total);
    }

    public function test_fresh_read_excludes_self_for_descendants_count(): void
    {
        $this->buildTree();

        // Root has 3 descendants (A, A1, B).
        $this->assertSame(3, (int) $this->freshById($this->ids['Root'], ['descendants_count'])->descendants_count);
        // A has 1 descendant (A1).
        $this->assertSame(1, (int) $this->freshById($this->ids['A'], ['descendants_count'])->descendants_count);
        // Leaves have 0.
        $this->assertSame(0, (int) $this->freshById($this->ids['A1'], ['descendants_count'])->descendants_count);
        $this->assertSame(0, (int) $this->freshById($this->ids['B'], ['descendants_count'])->descendants_count);
    }

    public function test_fresh_read_excludes_self_for_descendants_max(): void
    {
        $this->buildTree();

        // Root descendants are {20, 30, 40} → max = 40.
        $this->assertSame(40, (int) $this->freshById($this->ids['Root'], ['descendants_max'])->descendants_max);
        // A descendants are {30}.
        $this->assertSame(30, (int) $this->freshById($this->ids['A'], ['descendants_max'])->descendants_max);
        // Leaves: NULL (empty subtree).
        $this->assertNull($this->freshById($this->ids['A1'], ['descendants_max'])->descendants_max);
        $this->assertNull($this->freshById($this->ids['B'], ['descendants_max'])->descendants_max);
    }

    public function test_fresh_aggregate_scalar_for_exclusive_column(): void
    {
        $this->buildTree();

        $root = Branch::query()->findOrFail($this->ids['Root']);

        $this->assertSame(90, $this->asInt($root->freshAggregate('descendants_total')));
        $this->assertSame(3, $this->asInt($root->freshAggregate('descendants_count')));
        $this->assertSame(40, $this->asInt($root->freshAggregate('descendants_max')));
    }

    // ----------------------------------------------------------------
    // Incremental maintenance: save() keeps exclusive columns in sync
    // via the chain-recompute path. No fixAggregates() required.
    // ----------------------------------------------------------------

    public function test_save_maintains_exclusive_columns_on_create(): void
    {
        $this->buildTree();

        $root = $this->rowById('branches', $this->ids['Root']);
        $a = $this->rowById('branches', $this->ids['A']);
        $a1 = $this->rowById('branches', $this->ids['A1']);
        $b = $this->rowById('branches', $this->ids['B']);

        // Root: descendants (A, A1, B) total = 20+30+40 = 90, count 3, max 40.
        $this->assertSame(90, (int) $root->descendants_total);
        $this->assertSame(3, (int) $root->descendants_count);
        $this->assertSame(40, (int) $root->descendants_max);

        // A: only A1 descends.
        $this->assertSame(30, (int) $a->descendants_total);
        $this->assertSame(1, (int) $a->descendants_count);
        $this->assertSame(30, (int) $a->descendants_max);

        // Leaves report empty subtree.
        $this->assertSame(0, (int) $a1->descendants_total);
        $this->assertSame(0, (int) $a1->descendants_count);
        $this->assertNull($a1->descendants_max);

        $this->assertSame(0, (int) $b->descendants_total);

        // The inclusive baseline is also correct.
        $this->assertSame(100, (int) $root->tickets_total); // 10+20+30+40
    }

    public function test_save_maintains_exclusive_columns_on_source_update(): void
    {
        $this->buildTree();

        // Pre-update Root.descendants_total = 90.
        $root = $this->rowById('branches', $this->ids['Root']);
        $this->assertSame(90, (int) $root->descendants_total);

        // Bump A1's tickets from 30 → 50 (Δ = +20).
        $a1 = Branch::query()->findOrFail($this->ids['A1']);
        $a1->tickets = 50;
        $a1->save();

        // Root.descendants_total should follow: 90 → 110.
        $root = $this->rowById('branches', $this->ids['Root']);
        $this->assertSame(110, (int) $root->descendants_total);

        // A.descendants_total follows: 30 → 50.
        $a = $this->rowById('branches', $this->ids['A']);
        $this->assertSame(50, (int) $a->descendants_total);

        // A1 itself: its own descendants_total is unaffected by its
        // own source change (descendants-only — A1 has no descendants).
        $a1Row = $this->rowById('branches', $this->ids['A1']);
        $this->assertSame(0, (int) $a1Row->descendants_total);

        // descendants_max on Root: previously 40 (B), now 50 (A1).
        $this->assertSame(50, (int) $root->descendants_max);
    }

    public function test_save_maintains_exclusive_columns_on_delete(): void
    {
        $this->buildTree();

        // Delete A1. A's descendants_total goes 30 → 0; Root's 90 → 60.
        $a1 = Branch::query()->findOrFail($this->ids['A1']);
        $a1->delete();

        $root = $this->rowById('branches', $this->ids['Root']);
        $a = $this->rowById('branches', $this->ids['A']);

        $this->assertSame(60, (int) $root->descendants_total);  // 20+40
        $this->assertSame(2, (int) $root->descendants_count);
        $this->assertSame(40, (int) $root->descendants_max);   // B (A1 was 30, never the max)

        $this->assertSame(0, (int) $a->descendants_total);
        $this->assertSame(0, (int) $a->descendants_count);
        $this->assertNull($a->descendants_max);
    }

    public function test_aggregate_errors_reports_no_drift_when_maintained(): void
    {
        $this->buildTree();

        // Build + save should produce zero drift; aggregateErrors() reports 0.
        $errors = Branch::aggregateErrors();

        $this->assertSame(0, $errors['descendants_total'] ?? -1);
        $this->assertSame(0, $errors['descendants_count'] ?? -1);
        $this->assertSame(0, $errors['descendants_max'] ?? -1);
    }

    public function test_fix_aggregates_idempotent_after_save(): void
    {
        $this->buildTree();

        // First fixAggregates after a clean build should write nothing.
        $result = Branch::fixAggregates();
        $this->assertSame(0, $result->totalRowsUpdated);
    }

    // ----------------------------------------------------------------
    // Ad-hoc exclusive aggregate via the Aggregate::sum(...)->exclusive()
    // factory in withFreshAggregates() — exercises the fluent exclusive()
    // modifier path through TreeAggregateBuilder.
    // ----------------------------------------------------------------

    public function test_adhoc_exclusive_aggregate_in_with_fresh_aggregates(): void
    {
        $this->buildTree();

        $root = Branch::query()
            ->withFreshAggregates([
                'adhoc_desc_sum' => Aggregate::sum('tickets')->exclusive(),
            ])
            ->where('id', $this->ids['Root'])
            ->firstOrFail();

        $a = Branch::query()
            ->withFreshAggregates([
                'adhoc_desc_sum' => Aggregate::sum('tickets')->exclusive(),
            ])
            ->where('id', $this->ids['A'])
            ->firstOrFail();

        $a1 = Branch::query()
            ->withFreshAggregates([
                'adhoc_desc_sum' => Aggregate::sum('tickets')->exclusive(),
            ])
            ->where('id', $this->ids['A1'])
            ->firstOrFail();

        $this->assertSame(90, $this->asInt($root->getAttribute('adhoc_desc_sum')));
        $this->assertSame(30, $this->asInt($a->getAttribute('adhoc_desc_sum')));
        $this->assertSame(0, $this->asInt($a1->getAttribute('adhoc_desc_sum')));
    }
}
