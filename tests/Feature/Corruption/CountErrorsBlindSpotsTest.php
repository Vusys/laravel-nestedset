<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Corruption;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * countErrors() used to only check lft >= rgt, per-column duplicates, and
 * orphans — so a parent_id that disagreed with bounds, a drifted depth,
 * and a broken 1..2N permutation all read "healthy". These exercise the
 * three added categories against the exact blind-spot corruptions.
 */
final class CountErrorsBlindSpotsTest extends TestCase
{
    protected bool $allowBrokenTreeAtTearDown = true;

    private function seedValidTree(): void
    {
        // Root(1,6) → A(2,3), B(4,5)
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root', 'lft' => 1, 'rgt' => 6, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'A', 'lft' => 2, 'rgt' => 3, 'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'B', 'lft' => 4, 'rgt' => 5, 'depth' => 1, 'parent_id' => 1],
        ]);
        $this->syncSequence('categories');
    }

    #[Test]
    public function valid_tree_reads_clean_on_every_category(): void
    {
        $this->seedValidTree();
        $this->assertSame(0, array_sum(Category::countErrors()));
    }

    #[Test]
    public function parent_id_disagreeing_with_bounds_is_detected(): void
    {
        $this->seedValidTree();

        // Re-point A's parent at B without moving its interval — A's bounds
        // (2,3) are no longer inside B's bounds (4,5).
        DB::table('categories')->where('id', 2)->update(['parent_id' => 3]);

        $errors = Category::countErrors();
        $this->assertGreaterThan(0, $errors['parent_bounds_mismatch']);
        // Bounds-only checks still read clean.
        $this->assertSame(0, $errors['invalid_bounds']);
        $this->assertSame(0, $errors['duplicate_lft']);
    }

    #[Test]
    public function depth_drift_is_detected(): void
    {
        $this->seedValidTree();

        DB::table('categories')->where('id', 2)->update(['depth' => 5]);

        $errors = Category::countErrors();
        $this->assertGreaterThan(0, $errors['depth_mismatch']);
        $this->assertSame(0, $errors['invalid_bounds']);
    }

    #[Test]
    public function cross_column_collision_is_detected(): void
    {
        // X(0,1) overlapping Root(1,4): value 1 is both X.rgt and Root.lft,
        // and X.lft = 0 is below range. Reads clean on the old checks.
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root', 'lft' => 1, 'rgt' => 4, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'C', 'lft' => 2, 'rgt' => 3, 'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'X', 'lft' => 0, 'rgt' => 1, 'depth' => 0, 'parent_id' => null],
        ]);
        $this->syncSequence('categories');

        $errors = Category::countErrors();
        $this->assertGreaterThan(0, $errors['bounds_out_of_range']);
        $this->assertSame(0, $errors['invalid_bounds']);
        $this->assertSame(0, $errors['duplicate_lft']);
        $this->assertSame(0, $errors['duplicate_rgt']);
    }
}
