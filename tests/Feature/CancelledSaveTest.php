<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * A `saving` / `creating` listener returning `false` is the standard
 * Laravel idiom for cancelling a save. The trait's own `saving` listener
 * has already run the structural SQL (makeGap / moveNode) by the time a
 * later user listener cancels, and the auto-transaction only rolls back
 * on exceptions — so without intervention the gap/move commits with no
 * matching row write, corrupting the tree. The cancel must roll the
 * structural SQL back and return false.
 */
final class CancelledSaveTest extends TestCase
{
    use InteractsWithTrees;

    private bool $cancelSave = false;

    #[Test]
    public function cancelling_a_move_via_saving_rolls_back_the_structural_sql(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();

        $a = new Category(['name' => 'A']);
        $a->appendToNode($root->refresh())->save();

        $b = new Category(['name' => 'B']);
        $b->appendToNode($root->refresh())->save();

        $before = $this->boundsById();

        Category::saving(fn (): ?bool => $this->cancelSave ? false : null);
        $this->cancelSave = true;

        $result = $a->appendToNode($b->refresh())->save();
        $this->cancelSave = false;

        $this->assertFalse($result, 'save() must report the cancelled write');
        $this->assertSame($before, $this->boundsById(), 'tree must be untouched by a cancelled move');
        $this->assertIsChildOf($a->refresh(), $root->refresh());
    }

    #[Test]
    public function cancelling_an_insert_via_creating_leaves_no_gap(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();

        $before = $this->boundsById();

        Category::creating(fn (): ?bool => $this->cancelSave ? false : null);
        $this->cancelSave = true;

        $child = new Category(['name' => 'Child']);
        $result = $child->appendToNode($root->refresh())->save();
        $this->cancelSave = false;

        $this->assertFalse($result, 'save() must report the cancelled insert');
        $this->assertSame($before, $this->boundsById(), 'no 2-slot gap may be committed by a cancelled insert');
        $this->assertSame(0, $root->refresh()->getDescendantCount());
    }

    #[Test]
    public function retrying_an_insert_after_a_cancelled_save_does_not_duplicate_bounds(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();

        $a = new Category(['name' => 'A']);
        $a->appendToNode($root->refresh())->save();

        Category::creating(fn (): ?bool => $this->cancelSave ? false : null);
        $this->cancelSave = true;

        $child = new Category(['name' => 'Child']);
        $first = $child->appendToNode($root->refresh())->save();
        $this->assertFalse($first, 'first save must report the cancellation');

        // Retry the same poisoned instance now that the cancel is lifted.
        $this->cancelSave = false;
        $second = $child->save();

        $this->assertTrue($second, 'retry must succeed');
        $this->assertTreeIsIntact(Category::class);
        $this->assertIsChildOf($child->refresh(), $root->refresh());
    }

    #[Test]
    public function retrying_a_move_after_a_cancelled_save_keeps_the_tree_intact(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();

        $a = new Category(['name' => 'A']);
        $a->appendToNode($root->refresh())->save();

        $b = new Category(['name' => 'B']);
        $b->appendToNode($root->refresh())->save();

        Category::saving(fn (): ?bool => $this->cancelSave ? false : null);
        $this->cancelSave = true;

        $first = $a->appendToNode($b->refresh())->save();
        $this->assertFalse($first, 'first move must report the cancellation');

        $this->cancelSave = false;
        $second = $a->appendToNode($b->refresh())->save();

        $this->assertTrue($second, 'retry must succeed');
        $this->assertTreeIsIntact(Category::class);
        $this->assertIsChildOf($a->refresh(), $b->refresh());
    }

    #[Test]
    public function retrying_an_insert_after_a_throwing_listener_does_not_duplicate_bounds(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();

        $a = new Category(['name' => 'A']);
        $a->appendToNode($root->refresh())->save();

        Category::saved(function (): void {
            if ($this->cancelSave) {
                throw new \RuntimeException('listener blew up after the row write');
            }
        });
        $this->cancelSave = true;

        $child = new Category(['name' => 'Child']);
        try {
            $child->appendToNode($root->refresh())->save();
            $this->fail('the throwing listener should have propagated');
        } catch (\RuntimeException) {
            // expected — the auto-transaction rolled back
        }

        $this->cancelSave = false;
        $this->assertTrue($child->save(), 'retry must succeed');
        $this->assertTreeIsIntact(Category::class);
        $this->assertIsChildOf($child->refresh(), $root->refresh());
    }

    /**
     * @return array<int, array{lft: int, rgt: int, depth: int, parent_id: int|null}>
     */
    private function boundsById(): array
    {
        $out = [];
        foreach (DB::table('categories')->orderBy('id')->get() as $row) {
            $out[(int) $row->id] = [
                'lft' => (int) $row->lft,
                'rgt' => (int) $row->rgt,
                'depth' => (int) $row->depth,
                'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
            ];
        }

        return $out;
    }
}
