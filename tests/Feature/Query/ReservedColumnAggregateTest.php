<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Query;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\ReservedAggregateNode;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Aggregate maintenance (delta + recompute) and fresh-read subqueries must
 * grammar-quote the structural columns they interpolate. With
 * {@see ReservedAggregateNode}'s reserved-word columns (`left`/`right`/
 * `order`), any unquoted site is a backend-specific syntax error.
 */
final class ReservedColumnAggregateTest extends TestCase
{
    use InteractsWithTrees;

    /**
     * @return array{0: ReservedAggregateNode, 1: ReservedAggregateNode, 2: ReservedAggregateNode}
     */
    private function tree(): array
    {
        $root = new ReservedAggregateNode(['name' => 'Root', 'weight' => 1]);
        $root->saveAsRoot();

        $a = new ReservedAggregateNode(['name' => 'A', 'weight' => 10]);
        $a->appendToNode($root)->save();

        $b = new ReservedAggregateNode(['name' => 'B', 'weight' => 100]);
        $b->appendToNode($root)->save();

        return [$root->refresh(), $a->refresh(), $b->refresh()];
    }

    #[Test]
    public function aggregates_maintain_through_reserved_columns(): void
    {
        [$root, $a] = $this->tree();

        // Inclusive SUM, exclusive SUM, exclusive MAX all maintained on insert.
        $this->assertSame(111, (int) $root->weight_total);
        $this->assertSame(110, (int) $root->weight_sub);
        $this->assertSame(100, (int) $root->weight_max);
        $this->assertAggregatesAreIntact(ReservedAggregateNode::class);

        // Move + delete drive the recompute path (exclusive/MAX).
        $c = new ReservedAggregateNode(['name' => 'C', 'weight' => 50]);
        $c->appendToNode($a->refresh())->save();
        $this->assertAggregatesAreIntact(ReservedAggregateNode::class);

        $c->refresh()->delete();
        $this->assertAggregatesAreIntact(ReservedAggregateNode::class);
    }

    #[Test]
    public function fresh_aggregate_read_runs_through_reserved_columns(): void
    {
        [$root] = $this->tree();

        // withFreshAggregates drives the correlated/LATERAL/derived read path.
        $fresh = ReservedAggregateNode::query()
            ->whereKey($root->getKey())
            ->withFreshAggregates(['live_total' => Aggregate::sum('weight')])
            ->firstOrFail();

        $this->assertEquals(111, $fresh->getAttribute('live_total'));
    }
}
