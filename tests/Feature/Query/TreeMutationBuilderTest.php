<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Query;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Columns;
use Vusys\NestedSet\NodeBounds;
use Vusys\NestedSet\Query\TreeMutationBuilder;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Tests for TreeMutationBuilder.
 *
 * Each test seeds a known tree then mutates it and asserts the resulting
 * lft/rgt/depth values directly. The initial tree shape:
 *
 *  Root        lft=1  rgt=10  depth=0
 *    Child A   lft=2  rgt=7   depth=1
 *      AA      lft=3  rgt=4   depth=2
 *      AB      lft=5  rgt=6   depth=2
 *    Child B   lft=8  rgt=9   depth=1
 */
final class TreeMutationBuilderTest extends TestCase
{
    private TreeMutationBuilder $mutator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mutator = new TreeMutationBuilder(
            connection: DB::connection(),
            table: 'categories',
            lft: Columns::LFT,
            rgt: Columns::RGT,
            parentId: Columns::PARENT_ID,
            depth: Columns::DEPTH,
        );

        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root',    'lft' => 1,  'rgt' => 10, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'Child A', 'lft' => 2,  'rgt' => 7,  'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'AA',      'lft' => 3,  'rgt' => 4,  'depth' => 2, 'parent_id' => 2],
            ['id' => 4, 'name' => 'AB',      'lft' => 5,  'rgt' => 6,  'depth' => 2, 'parent_id' => 2],
            ['id' => 5, 'name' => 'Child B', 'lft' => 8,  'rgt' => 9,  'depth' => 1, 'parent_id' => 1],
        ]);
    }

    // ----------------------------------------------------------------
    // makeGap / closeGap
    // ----------------------------------------------------------------

    public function test_make_gap_shifts_nodes_at_and_after_position(): void
    {
        // Open a 2-wide gap at position 8 (before Child B).
        $this->mutator->makeGap(at: 8, size: 2);

        $root = $this->rowById('categories', 1);
        $childB = $this->rowById('categories', 5);
        $childA = $this->rowById('categories', 2);

        $this->assertSame(12, (int) $root->rgt);   // 10 + 2
        $this->assertSame(10, (int) $childB->lft);  // 8 + 2
        $this->assertSame(11, (int) $childB->rgt);  // 9 + 2
        $this->assertSame(2, (int) $childA->lft);  // unchanged
        $this->assertSame(7, (int) $childA->rgt);  // unchanged
    }

    public function test_make_gap_does_not_shift_nodes_before_position(): void
    {
        $this->mutator->makeGap(at: 6, size: 2);

        $aa = $this->rowById('categories', 3);

        $this->assertSame(3, (int) $aa->lft); // unchanged (lft=3 < 6)
        $this->assertSame(4, (int) $aa->rgt); // unchanged (rgt=4 < 6)
    }

    public function test_close_gap_shifts_nodes_after_position(): void
    {
        // Remove Child B's space by closing a 2-wide gap after position 7.
        $this->mutator->closeGap(at: 7, size: 2);

        $root = $this->rowById('categories', 1);
        $childB = $this->rowById('categories', 5);

        $this->assertSame(8, (int) $root->rgt);   // 10 - 2
        $this->assertSame(6, (int) $childB->lft);  // 8 - 2
        $this->assertSame(7, (int) $childB->rgt);  // 9 - 2
    }

    // ----------------------------------------------------------------
    // insertNode
    // ----------------------------------------------------------------

    public function test_insert_node_returns_correct_position_data(): void
    {
        $data = $this->mutator->insertNode(insertAt: 8, newDepth: 1, newParentId: 1);

        $this->assertSame(8, $data['lft']);
        $this->assertSame(9, $data['rgt']);
        $this->assertSame(1, $data['depth']);
        $this->assertSame(1, $data['parent_id']);
    }

    public function test_insert_node_returns_null_parent_for_root(): void
    {
        $data = $this->mutator->insertNode(insertAt: 1, newDepth: 0, newParentId: null);

        $this->assertNull($data['parent_id']);
    }

    // ----------------------------------------------------------------
    // moveNode
    // ----------------------------------------------------------------

    public function test_move_node_forward_single_leaf(): void
    {
        // Move AA (lft=3, rgt=4) to after AB (lft=5, rgt=6).
        // After move: AB should be at 3-4, AA at 5-6.
        $from = new NodeBounds(lft: 3, rgt: 4, depth: 2);
        $targetLft = 5; // desired final lft of AA in the final state

        $this->mutator->moveNode(from: $from, targetLft: $targetLft, depthDelta: 0);

        $aa = $this->rowById('categories', 3);
        $ab = $this->rowById('categories', 4);

        $this->assertSame(5, (int) $aa->lft);
        $this->assertSame(6, (int) $aa->rgt);
        $this->assertSame(3, (int) $ab->lft);
        $this->assertSame(4, (int) $ab->rgt);
    }

    public function test_move_node_backward_single_leaf(): void
    {
        // Move AB (lft=5, rgt=6) to before AA (lft=3, rgt=4).
        // After move: AB should be at 3-4, AA at 5-6.
        $from = new NodeBounds(lft: 5, rgt: 6, depth: 2);
        $targetLft = 3;

        $this->mutator->moveNode(from: $from, targetLft: $targetLft, depthDelta: 0);

        $aa = $this->rowById('categories', 3);
        $ab = $this->rowById('categories', 4);

        $this->assertSame(5, (int) $aa->lft);
        $this->assertSame(6, (int) $aa->rgt);
        $this->assertSame(3, (int) $ab->lft);
        $this->assertSame(4, (int) $ab->rgt);
    }

    public function test_move_node_updates_depth_delta(): void
    {
        // Move Child B (lft=8, rgt=9, depth=1) to just after AB (lft=5-6).
        // targetLft=7 means Child B ends at lft=7 in the final state (inside Child A's subtree).
        $from = new NodeBounds(lft: 8, rgt: 9, depth: 1);
        $targetLft = 7; // final lft of Child B (backward move)

        $this->mutator->moveNode(from: $from, targetLft: $targetLft, depthDelta: 1);

        $childB = $this->rowById('categories', 5);

        $this->assertSame(2, (int) $childB->depth); // 1 + 1
    }

    public function test_move_node_no_op_when_same_position(): void
    {
        $from = new NodeBounds(lft: 3, rgt: 4, depth: 2);

        $before = DB::table('categories')->orderBy('lft')->pluck('lft')->all();
        $this->mutator->moveNode(from: $from, targetLft: 3, depthDelta: 0);
        $after = DB::table('categories')->orderBy('lft')->pluck('lft')->all();

        $this->assertSame($before, $after);
    }

    // ----------------------------------------------------------------
    // getPlainNodeData / getNodeData
    // ----------------------------------------------------------------

    public function test_get_plain_node_data_returns_correct_values(): void
    {
        $data = $this->mutator->getPlainNodeData(id: 2);

        $this->assertSame(2, $data['lft']);
        $this->assertSame(7, $data['rgt']);
        $this->assertSame(1, $data['depth']);
        $this->assertSame(1, $data['parent_id']);
    }

    public function test_get_plain_node_data_returns_null_parent_for_root(): void
    {
        $data = $this->mutator->getPlainNodeData(id: 1);

        $this->assertNull($data['parent_id']);
    }

    public function test_get_node_data_returns_node_bounds(): void
    {
        $bounds = $this->mutator->getNodeData(id: 2);

        $this->assertSame(2, $bounds->lft);
        $this->assertSame(7, $bounds->rgt);
        $this->assertSame(1, $bounds->depth);
    }

    public function test_get_plain_node_data_throws_for_missing_node(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->mutator->getPlainNodeData(id: 999);
    }

    // ----------------------------------------------------------------
    // Query count assertions
    // ----------------------------------------------------------------

    public function test_make_gap_issues_one_query(): void
    {
        $count = 0;
        DB::listen(static function () use (&$count): void {
            $count++;
        });

        $this->mutator->makeGap(at: 8, size: 2);

        $this->assertSame(1, $count);
    }

    public function test_move_node_issues_one_query_when_moving(): void
    {
        $count = 0;
        DB::listen(static function () use (&$count): void {
            $count++;
        });

        $this->mutator->moveNode(
            from: new NodeBounds(lft: 3, rgt: 4, depth: 2),
            targetLft: 6,
            depthDelta: 0,
        );

        $this->assertSame(1, $count);
    }
}
