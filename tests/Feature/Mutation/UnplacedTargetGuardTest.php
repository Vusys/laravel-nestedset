<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Mutation;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Exceptions\UnplacedNodeException;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Defence-in-depth for mutation targets that exist on disk with
 * lft=rgt=0 — possible via raw inserts, fixture seeders, or recovery
 * from a corrupted prior state. The normal save path is already
 * guarded in `NodeTrait::bootNodeTrait`, but that only covers
 * placing a *new* node. If a caller loads an unplaced row and hands
 * it to `appendToNode($unplaced)`, the mutation would otherwise read
 * the zero bounds via `freshBoundsOf`, compute `position = 0`, and
 * call `makeGap(0, 2)` — shifting every row in the scope up by 2
 * and leaving the unplaced target at (2,2) with the new node at
 * (0,1), outside its purported parent.
 *
 * Mirrors the destination check already present in
 * `HasSubtreeClone::guardCloneDestination` so all destination-style
 * APIs treat unplaced targets consistently.
 */
final class UnplacedTargetGuardTest extends TestCase
{
    protected bool $allowBrokenTreeAtTearDown = true;

    private function seedUnplacedCategory(string $name): Category
    {
        // Bypass Eloquent so the bootNodeTrait new-row guard doesn't
        // intercept. Models loaded back from DB land with $exists = true,
        // which is exactly the load-from-corrupted-state shape we're
        // guarding against.
        $id = DB::table('categories')->insertGetId([
            'name' => $name,
            'lft' => 0,
            'rgt' => 0,
            'depth' => 0,
            'parent_id' => null,
        ]);

        $unplaced = Category::query()->findOrFail($id);
        $this->assertFalse($unplaced->isPlacedInTree());

        return $unplaced;
    }

    public function test_append_to_node_rejects_unplaced_target(): void
    {
        $unplaced = $this->seedUnplacedCategory('Unplaced');

        $child = new Category(['name' => 'Child']);
        $child->appendToNode($unplaced);

        $this->expectException(UnplacedNodeException::class);
        $child->save();
    }

    public function test_prepend_to_node_rejects_unplaced_target(): void
    {
        $unplaced = $this->seedUnplacedCategory('Unplaced');

        $child = new Category(['name' => 'Child']);
        $child->prependToNode($unplaced);

        $this->expectException(UnplacedNodeException::class);
        $child->save();
    }

    public function test_insert_before_node_rejects_unplaced_sibling(): void
    {
        $unplaced = $this->seedUnplacedCategory('Unplaced');

        $sibling = new Category(['name' => 'Sibling']);
        $sibling->insertBeforeNode($unplaced);

        $this->expectException(UnplacedNodeException::class);
        $sibling->save();
    }

    public function test_insert_after_node_rejects_unplaced_sibling(): void
    {
        $unplaced = $this->seedUnplacedCategory('Unplaced');

        $sibling = new Category(['name' => 'Sibling']);
        $sibling->insertAfterNode($unplaced);

        $this->expectException(UnplacedNodeException::class);
        $sibling->save();
    }

    public function test_guard_does_not_corrupt_pre_existing_tree(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $existing = new Category(['name' => 'Existing']);
        $existing->appendToNode($root)->save();

        $unplaced = $this->seedUnplacedCategory('Unplaced');

        $child = new Category(['name' => 'Child']);
        $child->appendToNode($unplaced);

        try {
            $child->save();
            $this->fail('Expected UnplacedNodeException.');
        } catch (UnplacedNodeException) {
            // Expected.
        }

        // Pre-existing tree is untouched — no rows shifted by an
        // accidental makeGap(0, 2) against the unplaced target.
        $root->refresh();
        $existing->refresh();
        $this->assertSame(1, $root->lft);
        $this->assertSame(4, $root->rgt);
        $this->assertSame(2, $existing->lft);
        $this->assertSame(3, $existing->rgt);
    }
}
