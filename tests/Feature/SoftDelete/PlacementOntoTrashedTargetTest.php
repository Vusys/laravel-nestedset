<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\SoftDelete;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Exceptions\TrashedTargetException;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Decision #11 — a live node may not be placed relative to a soft-deleted
 * anchor. The trashed node keeps its lft/rgt slot for restore, so without
 * the guard the placement would succeed structurally and strand a live
 * node under (append/prepend) or beside (before/after) a hidden one.
 *
 * Tree:
 *   Root
 *     A   (soft-deleted, with child AA)
 *     B
 */
final class PlacementOntoTrashedTargetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root', 'lft' => 1, 'rgt' => 8, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'A',    'lft' => 2, 'rgt' => 5, 'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'AA',   'lft' => 3, 'rgt' => 4, 'depth' => 2, 'parent_id' => 2],
            ['id' => 4, 'name' => 'B',    'lft' => 6, 'rgt' => 7, 'depth' => 1, 'parent_id' => 1],
        ]);
        $this->syncSequence('categories');

        Category::query()->findOrFail(2)->delete(); // soft-delete A (+ AA)
    }

    #[Test]
    public function append_onto_trashed_target_throws(): void
    {
        $trashed = Category::withTrashed()->findOrFail(2);

        $this->expectException(TrashedTargetException::class);

        (new Category(['name' => 'New']))->appendToNode($trashed)->save();
    }

    #[Test]
    public function prepend_onto_trashed_target_throws(): void
    {
        $trashed = Category::withTrashed()->findOrFail(2);

        $this->expectException(TrashedTargetException::class);

        (new Category(['name' => 'New']))->prependToNode($trashed)->save();
    }

    #[Test]
    public function insert_before_trashed_target_throws(): void
    {
        $trashed = Category::withTrashed()->findOrFail(2);

        $this->expectException(TrashedTargetException::class);

        (new Category(['name' => 'New']))->insertBeforeNode($trashed)->save();
    }

    #[Test]
    public function insert_after_trashed_target_throws(): void
    {
        $trashed = Category::withTrashed()->findOrFail(2);

        $this->expectException(TrashedTargetException::class);

        (new Category(['name' => 'New']))->insertAfterNode($trashed)->save();
    }

    #[Test]
    public function guard_reads_the_targets_own_trashed_flag(): void
    {
        // The guard consults the target's in-memory trashed() flag (no
        // extra deleted_at SELECT per placement). A freshly-loaded trashed
        // target carries the stamp and is rejected.
        $trashed = Category::withTrashed()->findOrFail(2);

        $this->assertTrue($trashed->trashed());

        $this->expectException(TrashedTargetException::class);

        (new Category(['name' => 'New']))->appendToNode($trashed)->save();
    }

    #[Test]
    public function placement_relative_to_a_live_sibling_of_a_trashed_node_is_allowed(): void
    {
        // B is live; appending under it must still work even though its
        // sibling A is trashed.
        $b = Category::query()->findOrFail(4);

        (new Category(['name' => 'New']))->appendToNode($b)->save();

        $this->assertSame(
            ['New'],
            Category::query()->where('parent_id', 4)->pluck('name')->all(),
        );
    }
}
