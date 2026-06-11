<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * A queued tree operation can be abandoned explicitly, and is dropped on
 * delete() — otherwise a queued-then-deleted model would re-dispatch the
 * stale op on a later save() and corrupt the tree.
 */
final class ClearPendingOperationTest extends TestCase
{
    use InteractsWithTrees;

    #[Test]
    public function clear_pending_operation_discards_a_queued_op(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->makeRoot()->save();

        $a = new Category(['name' => 'A']);
        $a->appendToNode($root->refresh())->save();

        $b = new Category(['name' => 'B']);
        $b->appendToNode($root->refresh())->save();

        $a->appendToNode($b->refresh());
        $this->assertTrue($a->hasPendingOperation());

        $a->clearPendingOperation();
        $this->assertFalse($a->hasPendingOperation());

        // Saving now is a no-op move — A stays under Root.
        $a->save();
        $this->assertIsChildOf($a->refresh(), $root->refresh());
        $this->assertTreeIsIntact(Category::class);
    }

    #[Test]
    public function delete_clears_a_queued_op(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->makeRoot()->save();

        $a = new Category(['name' => 'A']);
        $a->appendToNode($root->refresh())->save();

        $b = new Category(['name' => 'B']);
        $b->appendToNode($root->refresh())->save();

        // Queue a move, then delete the model before saving the move.
        $b->appendToNode($a->refresh());
        $b->delete();

        $this->assertFalse($b->hasPendingOperation());

        // A stray later save() must not re-dispatch the stale op.
        $b->save();
        $this->assertTreeIsIntact(Category::class);
    }
}
