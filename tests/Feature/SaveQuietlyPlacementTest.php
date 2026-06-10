<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Exceptions\UnplacedNodeException;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Placement (queued-operation dispatch + the unplaced-node guard) lives
 * in the `saving` listener, which saveQuietly() suppresses via
 * withoutEvents(). That made saveQuietly() drop the pending placement and
 * persist lft = rgt = 0 silently — core write logic must not be
 * skippable by going quiet. saveQuietly() now refuses rather than corrupt.
 */
final class SaveQuietlyPlacementTest extends TestCase
{
    #[Test]
    public function save_quietly_rejects_a_queued_placement(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();

        $child = new Category(['name' => 'Child']);
        $child->appendToNode($root->refresh());

        $this->expectException(UnplacedNodeException::class);
        $child->saveQuietly();
    }

    #[Test]
    public function save_quietly_rejects_a_new_unplaced_node(): void
    {
        $node = new Category(['name' => 'Orphan']);

        $this->expectException(UnplacedNodeException::class);
        $node->saveQuietly();
    }

    #[Test]
    public function save_quietly_still_works_on_a_placed_node(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();

        $root->name = 'Renamed';
        $this->assertTrue($root->saveQuietly());
        $this->assertSame('Renamed', Category::query()->whereKey($root->getKey())->firstOrFail()->name);
    }
}
