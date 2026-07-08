<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\SoftDelete;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Exceptions\TrashedAncestorException;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\Fixtures\Models\SoftBranch;
use Vusys\NestedSet\Tests\TestCase;

/**
 * `restore()` on a node whose parent is still soft-deleted is rejected
 * with {@see TrashedAncestorException}. The restore cascade only walks
 * *down* — it brings back the anchor plus its same-stamp descendants but
 * never restores ancestors — so a partial restore would leave a live
 * child parented under a trashed one. That's the same "live child under a
 * trashed parent" state the insert / factory path already forbids; this
 * guard keeps the invariant total in both directions (issue #218).
 *
 * Restoring from the *top* of a trashed subtree (parent live, or the node
 * is a root) is always allowed. A trashed child under a *live* parent is
 * also fine — only a still-trashed parent trips the guard.
 */
final class RestoreUnderTrashedParentTest extends TestCase
{
    use InteractsWithTrees;

    #[Test]
    public function restoring_a_child_whose_parent_is_trashed_throws(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();

        $parent = new Category(['name' => 'Parent']);
        $parent->appendToNode($root->refresh())->save();

        $child = new Category(['name' => 'Child']);
        $child->appendToNode($parent->refresh())->save();

        // Trash Parent → cascade stamps Child too.
        $parent->refresh()->delete();

        try {
            Category::withTrashed()->findOrFail($child->id)->restore();
            $this->fail('Expected TrashedAncestorException restoring a child under a trashed parent.');
        } catch (TrashedAncestorException $e) {
            $this->assertStringContainsString((string) $child->id, $e->getMessage());
            $this->assertStringContainsString((string) $parent->id, $e->getMessage());
        }

        // The guard runs before any write, so nothing moved: Child stays
        // trashed under the still-trashed Parent.
        $this->assertNotNull(Category::withTrashed()->findOrFail($child->id)->deleted_at);
        $this->assertNotNull(Category::withTrashed()->findOrFail($parent->id)->deleted_at);
    }

    #[Test]
    public function restoring_from_the_top_of_a_trashed_subtree_is_allowed(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();

        $parent = new Category(['name' => 'Parent']);
        $parent->appendToNode($root->refresh())->save();

        $child = new Category(['name' => 'Child']);
        $child->appendToNode($parent->refresh())->save();

        // Trash the subtree top (Parent) — its parent (Root) is live.
        $parent->refresh()->delete();

        // Restoring the top is fine; the whole same-stamp subtree returns.
        Category::withTrashed()->findOrFail($parent->id)->restore();

        $this->assertNull(Category::findOrFail($parent->id)->deleted_at);
        $this->assertNull(Category::findOrFail($child->id)->deleted_at);
        $this->assertSame(0, array_sum(Category::countErrors()));
    }

    #[Test]
    public function restoring_a_root_is_always_allowed(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();

        $child = new Category(['name' => 'Child']);
        $child->appendToNode($root->refresh())->save();

        $root->refresh()->delete();

        // A root has no parent, so the guard never fires.
        Category::withTrashed()->findOrFail($root->id)->restore();

        $this->assertNull(Category::findOrFail($root->id)->deleted_at);
        $this->assertNull(Category::findOrFail($child->id)->deleted_at);
    }

    #[Test]
    public function rejected_restore_leaves_aggregates_untouched(): void
    {
        $root = new SoftBranch(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();

        $parent = new SoftBranch(['name' => 'Parent', 'tickets' => 10]);
        $parent->appendToNode($root->refresh())->save();

        $child = new SoftBranch(['name' => 'Child', 'tickets' => 5]);
        $child->appendToNode($parent->refresh())->save();

        // Trash Parent → Root's rolled-up total drops to 0.
        $parent->refresh()->delete();
        $this->assertSame(0, (int) $root->refresh()->tickets_total);

        try {
            SoftBranch::withTrashed()->whereKey($child->getKey())->firstOrFail()->restore();
            $this->fail('Expected TrashedAncestorException restoring a child under a trashed parent.');
        } catch (TrashedAncestorException) {
            // expected — fall through to the drift assertions below.
        }

        // No partial write happened — stored aggregates still agree with a
        // fresh recompute and the total is unchanged.
        $this->assertSame(0, (int) $root->refresh()->tickets_total);
        $this->assertAggregatesAreIntact(SoftBranch::class);
    }
}
