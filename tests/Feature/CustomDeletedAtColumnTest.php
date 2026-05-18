<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Tests\Fixtures\Models\ArchivedBranch;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Pins that every soft-delete-aware path resolves the deleted-at
 * column via the model's `getDeletedAtColumn()` instead of hard-coding
 * `'deleted_at'`. ArchivedBranch sets `const DELETED_AT = 'archived_at'`
 * — if any path leaks the literal, these tests will diverge from the
 * default-column behaviour.
 *
 * Covers:
 *  - HasSoftDeleteTree::applySoftDeleteCascade()
 *  - HasSoftDeleteTree::applyRestoreCascade()
 *  - NodeTrait deleted-listener guard against double-decrementing
 *    aggregates on `forceDelete()` of an already-soft-deleted row.
 *  - HasNestedSetAggregates::replicate() clearing the soft-delete
 *    column on the clone.
 *  - TreeAggregateBuilder bulk fresh-aggregate paths
 *    (withFreshAggregates) filtering trashed descendants.
 */
final class CustomDeletedAtColumnTest extends TestCase
{
    public function test_soft_delete_cascades_using_custom_archived_at_column(): void
    {
        [$root, $left, $right] = $this->seedThreeNodeTree();

        $root->delete();

        $rootRow = $this->rowById('archived_branches', $root->id);
        $leftRow = $this->rowById('archived_branches', $left->id);
        $rightRow = $this->rowById('archived_branches', $right->id);

        $this->assertNotNull($rootRow->archived_at, 'root should have an archived_at timestamp');
        $this->assertNotNull($leftRow->archived_at, 'cascade should have set archived_at on left child');
        $this->assertNotNull($rightRow->archived_at, 'cascade should have set archived_at on right child');

        // No row should have a `deleted_at` column written by mistake.
        $hasDeletedAtColumn = DB::connection()->getSchemaBuilder()->hasColumn('archived_branches', 'deleted_at');
        $this->assertFalse($hasDeletedAtColumn, 'archived_branches must not have a deleted_at column');
    }

    public function test_restore_cascades_using_custom_archived_at_column(): void
    {
        [$root, $left, $right] = $this->seedThreeNodeTree();
        $root->delete();

        $trashedRoot = ArchivedBranch::query()->withTrashed()->findOrFail($root->id);
        $trashedRoot->restore();

        $rootRow = $this->rowById('archived_branches', $root->id);
        $leftRow = $this->rowById('archived_branches', $left->id);
        $rightRow = $this->rowById('archived_branches', $right->id);

        $this->assertNull($rootRow->archived_at, 'root archived_at should be cleared by restore');
        $this->assertNull($leftRow->archived_at, 'left child archived_at should be cleared by cascade restore');
        $this->assertNull($rightRow->archived_at, 'right child archived_at should be cleared by cascade restore');
    }

    public function test_force_delete_after_soft_delete_does_not_double_decrement_aggregates(): void
    {
        // Two children + root. After soft-deleting `left`, root's
        // tickets_total drops by left's tickets. If NodeTrait's
        // $alreadyTrashed guard reads the wrong column, force-deleting
        // `left` would fire applyAggregateOnDelete a second time and
        // subtract its tickets again — root's total would underflow.
        [$root, $left] = $this->seedThreeNodeTree();

        $root->refresh();
        $expectedTotal = (int) $root->tickets_total - (int) $left->tickets;

        $left->delete();
        $root->refresh();
        $this->assertSame($expectedTotal, (int) $root->tickets_total, 'soft-delete should decrement once');

        $trashedLeft = ArchivedBranch::query()->withTrashed()->findOrFail($left->id);
        $trashedLeft->forceDelete();
        $root->refresh();

        $this->assertSame($expectedTotal, (int) $root->tickets_total, 'force-delete after soft-delete must not decrement again');
    }

    public function test_replicate_clears_custom_archived_at_on_the_clone(): void
    {
        [, $left] = $this->seedThreeNodeTree();

        // Soft-delete the source so `archived_at` is set.
        $left->delete();
        $trashed = ArchivedBranch::query()->withTrashed()->findOrFail($left->id);
        $this->assertNotNull($trashed->archived_at, 'precondition: trashed source has archived_at set');

        $clone = $trashed->replicate();

        $this->assertNull(
            $clone->getAttribute('archived_at'),
            'replicate() must clear the custom soft-delete column, not the literal deleted_at',
        );
    }

    public function test_with_fresh_aggregates_excludes_trashed_descendants(): void
    {
        [$root, $left, $right] = $this->seedThreeNodeTree();

        $left->delete();

        $rootFresh = ArchivedBranch::query()
            ->withFreshAggregates()
            ->where('id', $root->id)
            ->firstOrFail();

        // After soft-deleting `left`, the fresh-aggregate read for the
        // root must reflect only the live `right` child's tickets.
        $this->assertSame(
            (int) $right->tickets,
            (int) $rootFresh->tickets_total,
            'withFreshAggregates must filter trashed descendants using the custom soft-delete column',
        );
    }

    /**
     * @return array{0: ArchivedBranch, 1: ArchivedBranch, 2: ArchivedBranch}
     */
    private function seedThreeNodeTree(): array
    {
        $root = new ArchivedBranch(['name' => 'root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root->refresh();

        $left = new ArchivedBranch(['name' => 'left', 'tickets' => 5]);
        $left->appendToNode($root)->save();
        $left->refresh();

        $right = new ArchivedBranch(['name' => 'right', 'tickets' => 7]);
        $right->appendToNode($root)->save();
        $right->refresh();

        $root->refresh();

        return [$root, $left, $right];
    }
}
