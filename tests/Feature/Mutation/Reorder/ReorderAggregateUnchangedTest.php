<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Mutation\Reorder;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Reordering siblings doesn't change ancestry, so stored aggregate
 * values on ancestors must stay correct without any maintenance
 * UPDATE firing.
 *
 * The reorder path issues a raw UPDATE through the mutation builder
 * — it skips Eloquent's `saving` / `saved` events entirely, which
 * means the aggregate-maintenance listener never runs. This test
 * locks in both halves: the stored values are still correct against
 * `freshAggregate()`, AND no maintenance UPDATE fired.
 */
final class ReorderAggregateUnchangedTest extends TestCase
{
    use InteractsWithTrees;

    #[Test]
    public function reorder_does_not_change_ancestor_aggregates_or_fire_maintenance(): void
    {
        $root = new Area(['name' => 'root', 'tickets' => 10]);
        $root->saveAsRoot();
        $a = new Area(['name' => 'a', 'tickets' => 1]);
        $a->appendToNode($root)->save();
        $b = new Area(['name' => 'b', 'tickets' => 2]);
        $b->appendToNode($root->refresh())->save();
        $c = new Area(['name' => 'c', 'tickets' => 4]);
        $c->appendToNode($root->refresh())->save();

        $root = $root->refresh();

        $beforeTotal = $root->tickets_total;
        $beforeCount = $root->tickets_count_all;
        $beforeMin = $root->tickets_min;
        $beforeMax = $root->tickets_max;

        $aggregateColumns = ['tickets_total', 'tickets_count_all', 'tickets_avg', 'tickets_min', 'tickets_max'];

        $touchedAggregateUpdate = false;
        DB::listen(static function ($event) use (&$touchedAggregateUpdate, $aggregateColumns): void {
            $sql = $event->sql;
            if (! str_starts_with(ltrim(strtoupper($sql)), 'UPDATE')) {
                return;
            }
            foreach ($aggregateColumns as $col) {
                if (stripos($sql, $col) !== false) {
                    $touchedAggregateUpdate = true;
                    break;
                }
            }
        });

        $root->reorderChildren([$c->id, $a->id, $b->id]);

        $root = $root->refresh();

        $this->assertSame($beforeTotal, $root->tickets_total);
        $this->assertSame($beforeCount, $root->tickets_count_all);
        $this->assertSame($beforeMin, $root->tickets_min);
        $this->assertSame($beforeMax, $root->tickets_max);

        $this->assertFalse(
            $touchedAggregateUpdate,
            'A reorder must not fire an aggregate-maintenance UPDATE.',
        );

        $this->assertAggregatesAreIntact(Area::class);
    }
}
