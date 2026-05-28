<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Listeners;

use Vusys\NestedSet\Tests\Fixtures\Models\Monster;
use Vusys\NestedSet\Tests\TestCase;

/**
 * `freshAggregate()` for a listener column recomputes the value in PHP
 * by streaming the subtree through the listener. The Avg arm is covered
 * elsewhere; these pin the Min and Max arms and the `withTrashed` branch
 * that drops the soft-delete scope before recomputing.
 */
final class ListenerFreshReadTest extends TestCase
{
    /**
     * @return Monster the refreshed root of a 3-node tree
     */
    private function buildTree(): Monster
    {
        // base_power * level → weighted_power (Sum listener); level →
        // weakest/strongest (Min/Max listeners).
        $root = new Monster(['name' => 'root', 'type' => 'water', 'base_power' => 2, 'level' => 5]);
        $root->saveAsRoot();

        $a = new Monster(['name' => 'a', 'type' => 'fire', 'base_power' => 4, 'level' => 3]);
        $a->appendToNode($root)->save();

        $b = new Monster(['name' => 'b', 'type' => 'fire', 'base_power' => 1, 'level' => 8]);
        $b->appendToNode($root->refresh())->save();

        return $root->refresh();
    }

    public function test_fresh_read_returns_listener_min_and_max(): void
    {
        $root = $this->buildTree();

        // levels across the subtree: 5, 3, 8
        $this->assertSame(3, $root->freshAggregate('weakest_level'));
        $this->assertSame(8, $root->freshAggregate('strongest_level'));
    }

    public function test_fresh_read_with_trashed_includes_soft_deleted_descendants(): void
    {
        $root = $this->buildTree();

        // weighted_power = base_power * level summed: 10 + 12 + 8 = 30
        $this->assertSame(30, $root->freshAggregate('weighted_power'));

        // Soft-delete leaf b (base_power 1 * level 8 = 8).
        Monster::query()->where('name', 'b')->firstOrFail()->delete();

        // Default read drops the trashed contribution; withTrashed keeps it.
        $this->assertSame(22, $root->freshAggregate('weighted_power'));
        $this->assertSame(30, $root->freshAggregate('weighted_power', withTrashed: true));
    }
}
