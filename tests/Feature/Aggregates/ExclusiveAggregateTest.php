<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\AggregateRegistry;
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
    // Incremental maintenance skip: save() does NOT update exclusive
    // columns. They stay at the column default (0 / null) until
    // fixAggregates() is run.
    // ----------------------------------------------------------------

    public function test_save_does_not_maintain_exclusive_columns(): void
    {
        $this->buildTree();

        $root = $this->rowById('branches', $this->ids['Root']);

        // Exclusive columns are stored as default-0 / null at the row level
        // because no incremental maintenance writes them.
        $this->assertSame(0, (int) $root->descendants_total);
        $this->assertSame(0, (int) $root->descendants_count);
        $this->assertNull($root->descendants_max);

        // The inclusive baseline is maintained — sanity check.
        $this->assertSame(100, (int) $root->tickets_total); // 10+20+30+40
    }

    public function test_fix_aggregates_recovers_exclusive_columns(): void
    {
        $this->buildTree();

        $result = Branch::fixAggregates();

        $this->assertGreaterThan(0, $result->totalRowsUpdated);

        $root = $this->rowById('branches', $this->ids['Root']);
        $a = $this->rowById('branches', $this->ids['A']);
        $a1 = $this->rowById('branches', $this->ids['A1']);

        // Root: descendants exclude self.
        $this->assertSame(90, (int) $root->descendants_total);
        $this->assertSame(3, (int) $root->descendants_count);
        $this->assertSame(40, (int) $root->descendants_max);

        // A: only A1 descends.
        $this->assertSame(30, (int) $a->descendants_total);
        $this->assertSame(1, (int) $a->descendants_count);
        $this->assertSame(30, (int) $a->descendants_max);

        // Leaves: empty subtree.
        $this->assertSame(0, (int) $a1->descendants_total);
        $this->assertSame(0, (int) $a1->descendants_count);
        $this->assertNull($a1->descendants_max);
    }

    public function test_aggregate_errors_reports_drift_on_exclusive_columns(): void
    {
        $this->buildTree();

        // Pre-fixAggregates the exclusive columns are at default values
        // while the fresh computation says otherwise. aggregateErrors()
        // should flag the drift.
        $errors = Branch::aggregateErrors();

        $this->assertGreaterThan(0, $errors['descendants_total'] ?? 0);
        $this->assertGreaterThan(0, $errors['descendants_count'] ?? 0);
        $this->assertGreaterThan(0, $errors['descendants_max'] ?? 0);

        // After a fix, errors clear.
        Branch::fixAggregates();
        $errors = Branch::aggregateErrors();

        $this->assertSame(0, $errors['descendants_total'] ?? -1);
        $this->assertSame(0, $errors['descendants_count'] ?? -1);
        $this->assertSame(0, $errors['descendants_max'] ?? -1);
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
