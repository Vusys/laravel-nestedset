<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Query;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\ReservedColumnNode;
use Vusys\NestedSet\Tests\TestCase;

/**
 * The structural columns of {@see ReservedColumnNode} are SQL reserved
 * words (`left`, `right`, `order`). Every raw CASE-WHEN UPDATE in the
 * mutation engine — makeGap, closeGap, moveNode, reorderSiblings — and the
 * repair path must grammar-quote those identifiers, or each operation is a
 * backend-specific syntax error. The existing ReservedIdentifierTest only
 * covers read-side aliases; this drives the write path end to end.
 */
final class ReservedColumnMutationTest extends TestCase
{
    use InteractsWithTrees;

    private function tree(): ReservedColumnNode
    {
        $root = new ReservedColumnNode(['name' => 'Root']);
        $root->saveAsRoot();

        // appendToNode re-reads the target's bounds from the DB at dispatch,
        // so reusing the same $root instance across appends is safe.
        foreach (['A', 'B', 'C'] as $name) {
            $child = new ReservedColumnNode(['name' => $name]);
            $child->appendToNode($root)->save();
        }

        return $root->refresh();
    }

    #[Test]
    public function append_prepend_and_insert_run_through_reserved_columns(): void
    {
        $root = $this->tree();

        // prepend (makeGap at the front) + insertBefore/After (sibling gap)
        $first = new ReservedColumnNode(['name' => 'First']);
        $first->prependToNode($root)->save();

        $b = ReservedColumnNode::query()->where('name', 'B')->firstOrFail();
        $before = new ReservedColumnNode(['name' => 'BeforeB']);
        $before->insertBeforeNode($b)->save();

        $this->assertFalse(ReservedColumnNode::isBroken(), 'tree broke during insertion through reserved columns');
        $this->assertSame(
            ['First', 'A', 'BeforeB', 'B', 'C'],
            ReservedColumnNode::query()->where('parent', $root->getKey())->orderBy('left')->pluck('name')->all(),
        );
    }

    #[Test]
    public function move_runs_through_reserved_columns(): void
    {
        $this->tree();

        // Move C under A — exercises moveNode's band CASE on left/right/order.
        $a = ReservedColumnNode::query()->where('name', 'A')->firstOrFail();
        $c = ReservedColumnNode::query()->where('name', 'C')->firstOrFail();
        $c->appendToNode($a)->save();

        $this->assertFalse(ReservedColumnNode::isBroken(), 'tree broke during move through reserved columns');
        $this->assertIsChildOf($c->refresh(), $a);
    }

    #[Test]
    public function reorder_runs_through_reserved_columns(): void
    {
        $root = $this->tree();

        $a = ReservedColumnNode::query()->where('name', 'A')->firstOrFail();
        $b = ReservedColumnNode::query()->where('name', 'B')->firstOrFail();
        $c = ReservedColumnNode::query()->where('name', 'C')->firstOrFail();

        // reorderSiblings CASE-WHEN on left/right (@property int $id keeps
        // the key list typed for the level-9 analyser).
        $root->reorderChildren([$c->id, $a->id, $b->id]);

        $this->assertFalse(ReservedColumnNode::isBroken());
        $this->assertSame(
            ['C', 'A', 'B'],
            ReservedColumnNode::query()->where('parent', $root->getKey())->orderBy('left')->pluck('name')->all(),
        );
    }

    #[Test]
    public function delete_and_fix_tree_run_through_reserved_columns(): void
    {
        $root = $this->tree();

        // closeGap on delete.
        ReservedColumnNode::query()->where('name', 'B')->firstOrFail()->delete();
        $this->assertFalse(ReservedColumnNode::isBroken(), 'tree broke during delete through reserved columns');

        // fixTree rebuild walks parent->left/right/order.
        $result = ReservedColumnNode::fixTree();
        $this->assertFalse(ReservedColumnNode::isBroken());
        $this->assertSame(
            ['A', 'C'],
            ReservedColumnNode::query()->where('parent', $root->getKey())->orderBy('left')->pluck('name')->all(),
        );
        $this->assertGreaterThanOrEqual(0, $result->nodesUpdated);
    }
}
