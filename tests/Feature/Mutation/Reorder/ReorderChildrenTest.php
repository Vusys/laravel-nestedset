<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Mutation\Reorder;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Exceptions\InvalidSiblingOrderException;
use Vusys\NestedSet\Exceptions\UnplacedNodeException;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Built tree (per setUp):
 *   Root (1..14)
 *     A (2..5)   AA (3..4)
 *     B (6..9)   BB (7..8)
 *     C (10..13) CC (11..12)
 *
 * Validates the core reorderChildren() contract: membership checks,
 * subtree-aware shifts, identity no-op, and edge cases.
 */
final class ReorderChildrenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root', 'lft' => 1,  'rgt' => 14, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'A',    'lft' => 2,  'rgt' => 5,  'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'AA',   'lft' => 3,  'rgt' => 4,  'depth' => 2, 'parent_id' => 2],
            ['id' => 4, 'name' => 'B',    'lft' => 6,  'rgt' => 9,  'depth' => 1, 'parent_id' => 1],
            ['id' => 5, 'name' => 'BB',   'lft' => 7,  'rgt' => 8,  'depth' => 2, 'parent_id' => 4],
            ['id' => 6, 'name' => 'C',    'lft' => 10, 'rgt' => 13, 'depth' => 1, 'parent_id' => 1],
            ['id' => 7, 'name' => 'CC',   'lft' => 11, 'rgt' => 12, 'depth' => 2, 'parent_id' => 6],
        ]);
        $this->syncSequence('categories');
    }

    public function test_reorder_two_siblings_swaps_their_subtrees(): void
    {
        $root = Category::query()->findOrFail(1);

        // Trim down to two siblings for a focused swap.
        Category::query()->findOrFail(6)->forceDelete();

        $root = $root->refresh();

        $root->reorderChildren([4, 2]);

        $this->assertSame(
            ['B', 'A'],
            Category::query()->where('parent_id', 1)->orderBy('lft')->pluck('name')->all(),
        );

        // BB and AA shifted with their parents.
        $bb = Category::query()->findOrFail(5);
        $aa = Category::query()->findOrFail(3);
        $this->assertSame(2, Category::query()->findOrFail(4)->lft);
        $this->assertSame(3, $bb->lft);
        $this->assertSame(4, $bb->rgt);
        $this->assertSame(6, Category::query()->findOrFail(2)->lft);
        $this->assertSame(7, $aa->lft);
        $this->assertSame(8, $aa->rgt);

        $this->assertFalse(Category::isBroken());
    }

    public function test_reorder_three_siblings_cyclic_permutation(): void
    {
        // A,B,C → B,C,A
        $root = Category::query()->findOrFail(1);
        $root->reorderChildren([4, 6, 2]);

        $this->assertSame(
            ['B', 'C', 'A'],
            Category::query()->where('parent_id', 1)->orderBy('lft')->pluck('name')->all(),
        );

        // Each subtree contiguous, depth unchanged.
        $b = Category::query()->findOrFail(4);
        $c = Category::query()->findOrFail(6);
        $a = Category::query()->findOrFail(2);

        $this->assertSame([2, 5, 1], [$b->lft, $b->rgt, $b->depth]);
        $this->assertSame([6, 9, 1], [$c->lft, $c->rgt, $c->depth]);
        $this->assertSame([10, 13, 1], [$a->lft, $a->rgt, $a->depth]);

        // Descendants stayed at the same relative depth.
        $this->assertSame(2, Category::query()->findOrFail(5)->depth); // BB
        $this->assertSame(2, Category::query()->findOrFail(7)->depth); // CC
        $this->assertSame(2, Category::query()->findOrFail(3)->depth); // AA

        $this->assertFalse(Category::isBroken());
    }

    public function test_reorder_with_deeper_subtrees_shifts_every_descendant(): void
    {
        // Extend A's subtree by another level: AA → AAA.
        $aaa = new Category(['name' => 'AAA']);
        $aaa->appendToNode(Category::query()->findOrFail(3))->save();

        $root = Category::query()->findOrFail(1);

        // Move A to the end so its deeper subtree shifts the furthest.
        $root->reorderChildren([4, 6, 2]);

        $aaa = $aaa->refresh();
        $a = Category::query()->findOrFail(2);

        // AAA should be a strict descendant of A, two levels down.
        $this->assertTrue($a->getBounds()->contains($aaa->getBounds()));
        $this->assertSame(3, $aaa->depth);
        $this->assertFalse(Category::isBroken());
    }

    public function test_identity_reorder_is_silent_no_op_and_fires_no_query(): void
    {
        $root = Category::query()->findOrFail(1);

        $sniffer = new class
        {
            public int $updates = 0;
        };
        DB::listen(static function ($event) use ($sniffer): void {
            // Strip out the leading SELECT we issue to read parent bounds
            // and the children index — those are required setup. Only the
            // mutating UPDATE itself is what "no query fired" forbids.
            if (str_starts_with(ltrim(strtoupper((string) $event->sql)), 'UPDATE')) {
                $sniffer->updates++;
            }
        });

        $root->reorderChildren([2, 4, 6]);

        $this->assertSame(0, $sniffer->updates);
        $this->assertSame(
            ['A', 'B', 'C'],
            Category::query()->where('parent_id', 1)->orderBy('lft')->pluck('name')->all(),
        );
    }

    public function test_rejects_missing_child_with_message_listing_omitted_keys(): void
    {
        $root = Category::query()->findOrFail(1);

        $this->expectException(InvalidSiblingOrderException::class);
        $this->expectExceptionMessage('missing direct child key(s) [6]');

        // Leaving C (id 6) out.
        $root->reorderChildren([2, 4]);
    }

    public function test_rejects_foreign_key_with_message_listing_unknown_keys(): void
    {
        $root = Category::query()->findOrFail(1);

        $this->expectException(InvalidSiblingOrderException::class);
        $this->expectExceptionMessage('not direct children of the parent');

        // 999 is not a direct child of root.
        $root->reorderChildren([2, 4, 6, 999]);
    }

    public function test_rejects_grandchild_as_foreign_key(): void
    {
        $root = Category::query()->findOrFail(1);

        $this->expectException(InvalidSiblingOrderException::class);

        // AA (id=3) is a grandchild of root, not a direct child.
        $root->reorderChildren([2, 4, 6, 3]);
    }

    public function test_rejects_duplicate_keys(): void
    {
        $root = Category::query()->findOrFail(1);

        $this->expectException(InvalidSiblingOrderException::class);
        $this->expectExceptionMessage('duplicate key(s)');

        $root->reorderChildren([2, 4, 2]);
    }

    public function test_empty_parent_silently_noops_when_called_with_empty_list(): void
    {
        $leaf = Category::query()->findOrFail(3); // AA — a leaf

        $sniffer = new class
        {
            public int $updates = 0;
        };
        DB::listen(static function ($event) use ($sniffer): void {
            if (str_starts_with(ltrim(strtoupper((string) $event->sql)), 'UPDATE')) {
                $sniffer->updates++;
            }
        });

        $leaf->reorderChildren([]);

        $this->assertSame(0, $sniffer->updates);
    }

    public function test_empty_parent_with_supplied_keys_throws(): void
    {
        $leaf = Category::query()->findOrFail(3);

        $this->expectException(InvalidSiblingOrderException::class);

        $leaf->reorderChildren([99]);
    }

    public function test_accepts_model_instances_in_place_of_keys(): void
    {
        $root = Category::query()->findOrFail(1);
        $a = Category::query()->findOrFail(2);
        $b = Category::query()->findOrFail(4);
        $c = Category::query()->findOrFail(6);

        $root->reorderChildren([$c, $a, $b]);

        $this->assertSame(
            ['C', 'A', 'B'],
            Category::query()->where('parent_id', 1)->orderBy('lft')->pluck('name')->all(),
        );
    }

    public function test_unplaced_parent_throws(): void
    {
        $unplaced = new Category(['name' => 'Unplaced']);

        $this->expectException(UnplacedNodeException::class);

        $unplaced->reorderChildren([1]);
    }

    public function test_parent_id_unchanged_for_every_row(): void
    {
        $beforeParents = Category::query()
            ->orderBy('id')
            ->pluck('parent_id', 'id')
            ->all();

        Category::query()->findOrFail(1)->reorderChildren([6, 4, 2]);

        $afterParents = Category::query()
            ->orderBy('id')
            ->pluck('parent_id', 'id')
            ->all();

        $this->assertSame($beforeParents, $afterParents);
    }

    public function test_returns_self_after_reorder(): void
    {
        $root = Category::query()->findOrFail(1);
        $returned = $root->reorderChildren([6, 4, 2]);

        $this->assertSame($root, $returned);
    }
}
