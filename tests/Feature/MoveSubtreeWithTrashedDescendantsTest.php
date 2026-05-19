<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Moving a subtree that contains soft-deleted descendants must:
 *   1. Shift the descendants' lft/rgt with the rest of the subtree.
 *   2. Preserve their deleted_at timestamps (no implicit restore /
 *      cascade re-trash).
 *   3. Leave the destination's live aggregates correct.
 *
 * Audit reference: build/CORRECTNESS_AUDIT.md → T16.
 */
final class MoveSubtreeWithTrashedDescendantsTest extends TestCase
{
    public function test_move_preserves_trashed_descendant_timestamps_and_shifts_bounds(): void
    {
        // Tree:
        //   Root
        //   ├── A
        //   │   ├── A1 (will be soft-deleted before the move)
        //   │   └── A2
        //   └── B
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $root->refresh();

        $a = new Category(['name' => 'A']);
        $a->appendToNode($root)->save();
        $a->refresh();

        $a1 = new Category(['name' => 'A1']);
        $a1->appendToNode($a)->save();
        $a1->refresh();

        $a2 = new Category(['name' => 'A2']);
        $a2->appendToNode($a->refresh())->save();
        $a2->refresh();

        $b = new Category(['name' => 'B']);
        $b->appendToNode($root->refresh())->save();
        $b->refresh();

        // Soft-delete A1 (a descendant inside A's subtree).
        $a1->delete();
        $a1Trashed = Category::withTrashed()->where('name', 'A1')->firstOrFail();
        $trashedAt = $a1Trashed->deleted_at;
        $this->assertNotNull($trashedAt);
        $preLft = $a1Trashed->lft;
        $preRgt = $a1Trashed->rgt;

        // Move A under B. The structural shift must apply to A1's
        // bounds (it's inside A's subtree).
        $a = Category::query()->where('name', 'A')->firstOrFail();
        $a->appendToNode($b->refresh())->save();
        $a->refresh();

        // A1 still trashed with the same timestamp.
        $a1After = Category::withTrashed()->where('name', 'A1')->firstOrFail();
        $this->assertNotNull($a1After->deleted_at);
        $this->assertSame((string) $trashedAt, (string) $a1After->deleted_at);

        // A1's bounds shifted with A's subtree — they're not equal to
        // the pre-move values (the move SQL has moved them) and the
        // tree is still intact.
        $this->assertNotSame(
            [$preLft, $preRgt],
            [$a1After->lft, $a1After->rgt],
            'A1\'s bounds must shift with the moved subtree',
        );

        // The tree structure remains valid.
        $this->assertFalse(Category::isBroken());

        // A1 is still strictly inside A's new bounds.
        $a = Category::query()->where('name', 'A')->firstOrFail();
        $this->assertGreaterThan($a->lft, $a1After->lft);
        $this->assertLessThan($a->rgt, $a1After->rgt);

        // Querying via the default scope (excludes trashed) finds A
        // but not A1.
        $aLiveChildren = $a->descendants()->orderBy('lft')->pluck('name')->all();
        $this->assertSame(['A2'], $aLiveChildren);

        // Querying via withTrashed finds both — regardless of order.
        $aAllChildren = Category::withTrashed()
            ->where('lft', '>', $a->lft)
            ->where('rgt', '<', $a->rgt)
            ->orderBy('lft')
            ->pluck('name')
            ->all();
        sort($aAllChildren);
        $this->assertSame(['A1', 'A2'], $aAllChildren);
    }

    public function test_move_does_not_implicitly_restore_a_trashed_descendant(): void
    {
        // A move is structural, not lifecycle. Moving a subtree that
        // contains a soft-deleted descendant must not silently
        // restore it. Build Root → A → A1 plus Root → B, soft-delete
        // A1, move A under B, then verify A1's deleted_at survives.
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $root->refresh();

        $a = new Category(['name' => 'A']);
        $a->appendToNode($root)->save();
        $a->refresh();

        $a1 = new Category(['name' => 'A1']);
        $a1->appendToNode($a)->save();
        $a1->refresh();

        $b = new Category(['name' => 'B']);
        $b->appendToNode($root->refresh())->save();
        $b->refresh();

        // Soft-delete A1 only (not the whole A subtree).
        $a1->delete();
        $a1Trashed = Category::withTrashed()->where('name', 'A1')->firstOrFail();
        $trashedAt = (string) $a1Trashed->deleted_at;
        $this->assertNotEmpty($trashedAt);

        // Move A (with its trashed descendant A1) under B.
        $a = Category::query()->where('name', 'A')->firstOrFail();
        $a->appendToNode($b->refresh())->save();

        // A1 must still be trashed with the same timestamp — the move
        // shifted its bounds but left its lifecycle alone.
        $a1After = Category::withTrashed()->where('name', 'A1')->firstOrFail();
        $this->assertSame(
            $trashedAt,
            (string) $a1After->deleted_at,
            'A1 must stay trashed with the same deleted_at after its parent moves',
        );
    }
}
