<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\SubtreeClone;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\GuardedNode;
use Vusys\NestedSet\Tests\TestCase;

/**
 * cloneSubtreeTo is a deep-copy primitive: it must reproduce every
 * column, including guarded ones. Building the payload from raw DB rows
 * but mass-assigning through bulkInsertTree silently zeroed guarded
 * columns; the clone path now force-fills.
 */
final class CloneGuardedColumnsTest extends TestCase
{
    #[Test]
    public function clone_preserves_guarded_columns(): void
    {
        $root = new GuardedNode(['name' => 'Root']);
        $root->forceFill(['tickets' => 42]);
        $root->makeRoot()->save();

        $child = new GuardedNode(['name' => 'Child']);
        $child->forceFill(['tickets' => 7]);
        $child->appendToNode($root->refresh())->save();

        $target = new GuardedNode(['name' => 'Target']);
        $target->makeRoot()->save();

        $clone = $root->refresh()->cloneSubtreeTo($target->refresh());

        $this->assertSame(42, $clone->refresh()->tickets, 'guarded root column must be copied');
        $cloneChild = $clone->children()->firstOrFail();
        $this->assertSame(7, $cloneChild->tickets, 'guarded child column must be copied');
    }
}
