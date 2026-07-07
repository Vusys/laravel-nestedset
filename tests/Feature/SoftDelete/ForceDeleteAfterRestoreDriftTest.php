<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\SoftDelete;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Exceptions\TrashedAncestorException;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\SoftBranch;
use Vusys\NestedSet\Tests\TestCase;

/**
 * The old aggregate-drift limitation — force-deleting a trashed parent
 * that has an *individually restored* live child left the ancestor chain
 * counting a row that no longer existed — is now **unreachable through
 * the public API**. Its precondition was "restore a child while its
 * parent is still trashed", which `restore()` now rejects with
 * {@see TrashedAncestorException} (the cascade never walks up, so a
 * partial restore would leave a live child under a trashed parent).
 *
 * This test pins that the guard closes the hole: the live-child-under-a-
 * trashed-parent state can't be constructed, so the downstream drift can
 * never occur.
 */
final class ForceDeleteAfterRestoreDriftTest extends TestCase
{
    use InteractsWithTrees;

    #[Test]
    public function restoring_a_child_under_a_trashed_parent_is_rejected_so_the_drift_cannot_be_set_up(): void
    {
        $root = new SoftBranch(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();

        $parent = new SoftBranch(['name' => 'Parent', 'tickets' => 10]);
        $parent->appendToNode($root->refresh())->save();

        $child = new SoftBranch(['name' => 'Child', 'tickets' => 5]);
        $child->appendToNode($parent->refresh())->save();

        $this->assertSame(15, (int) $root->refresh()->tickets_total);

        // Soft-delete Parent → cascades to Child → Root loses 15.
        $parent->refresh()->delete();
        $this->assertSame(0, (int) $root->refresh()->tickets_total);

        // Individually restoring Child while Parent stays trashed is the
        // exact setup that used to produce the drift. It now throws.
        $this->expectException(TrashedAncestorException::class);
        SoftBranch::withTrashed()->whereKey($child->getKey())->firstOrFail()->restore();
    }
}
