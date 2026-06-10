<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Both the structural sibling-insert path and the aggregate before-move
 * hook used to read stale in-memory values off the node/sibling handed
 * to them. parent_id is the source of truth, so a wrong-parent insert is
 * silent corruption that fixTree() later "repairs" by relocating the
 * node; stale bounds in the aggregate hook leave the departed subtree's
 * contribution stuck on the old ancestor chain.
 */
final class StaleMovePathTest extends TestCase
{
    use InteractsWithTrees;

    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    #[Test]
    public function insert_after_a_relocated_sibling_uses_its_fresh_parent_id(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();

        $p1 = new Category(['name' => 'P1']);
        $p1->appendToNode($root->refresh())->save();

        $p2 = new Category(['name' => 'P2']);
        $p2->appendToNode($root->refresh())->save();

        $sibling = new Category(['name' => 'S']);
        $sibling->appendToNode($p1->refresh())->save();

        // Hold a stale handle (parent_id = P1) while a second instance
        // relocates the same row under P2.
        $staleSibling = Category::query()->whereKey($sibling->getKey())->firstOrFail();
        Category::query()->whereKey($sibling->getKey())->firstOrFail()
            ->appendToNode($p2->refresh())
            ->save();

        // Insert a new node after the stale sibling. It must land under
        // the sibling's CURRENT parent (P2) with parent_id pointing there.
        $newNode = new Category(['name' => 'N']);
        $newNode->insertAfterNode($staleSibling)->save();

        $newNode->refresh();
        $this->assertSame(
            $p2->getKey(),
            $newNode->parent_id,
            'new node parent_id must match the sibling\'s fresh parent (P2), not the stale one (P1)',
        );
        $this->assertIsChildOf($newNode, $p2->refresh());

        // parent_id agrees with bounds, so a repair pass leaves it put.
        Category::fixTree();
        $newNode->refresh();
        $this->assertSame($p2->getKey(), $newNode->parent_id, 'fixTree must not relocate the node');
    }

    #[Test]
    public function moving_a_stale_node_keeps_aggregates_intact(): void
    {
        $root = new Area(['name' => 'R', 'tickets' => 0]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 0]);
        $a->appendToNode($root->refresh())->save();

        $mover = new Area(['name' => 'M', 'tickets' => 5]);
        $mover->appendToNode($a->refresh())->save();

        $dest = new Area(['name' => 'B', 'tickets' => 0]);
        $dest->appendToNode($root->refresh())->save();

        // Stale handle on the mover (bounds captured before the shift).
        $staleMover = Area::query()->whereKey($mover->getKey())->firstOrFail();

        // A second instance prepends a node under A, shifting the mover's
        // lft/rgt rightward — the stale handle now holds out-of-date bounds.
        $shift = new Area(['name' => 'Z', 'tickets' => 0]);
        $shift->prependToNode($a->refresh())->save();

        // Move the stale mover under B. The before-move aggregate hook must
        // use the mover's fresh bounds, not the stale in-memory ones.
        $staleMover->appendToNode($dest->refresh())->save();

        $this->assertAggregatesAreIntact(Area::class);
    }
}
