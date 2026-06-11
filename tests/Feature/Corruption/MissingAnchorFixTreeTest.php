<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Corruption;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * An anchored fixTree() whose anchor row is gone must refuse rather than
 * rebuild the orphaned subtree from lft 1 — that overwrites the live
 * root and *creates* corruption. Mirrors AggregateRepair's guard.
 */
final class MissingAnchorFixTreeTest extends TestCase
{
    use InteractsWithTrees;

    protected bool $allowBrokenTreeAtTearDown = true;

    #[Test]
    public function anchored_fixtree_with_a_missing_anchor_throws_instead_of_corrupting(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->makeRoot()->save();

        $a = new Category(['name' => 'A']);
        $a->appendToNode($root->refresh())->save();

        $b = new Category(['name' => 'B']);
        $b->appendToNode($a->refresh())->save();

        // Raw-delete the anchor A, orphaning B (parent_id still points at A).
        DB::table('categories')->where('id', $a->id)->delete();

        $rootBoundsBefore = DB::table('categories')->where('id', $root->id)->first(['lft', 'rgt']);

        try {
            Category::fixTree($a);
            $this->fail('fixTree should refuse a missing anchor');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not found', $e->getMessage());
        }

        // The live root must be untouched — not overwritten at lft 1.
        $rootBoundsAfter = DB::table('categories')->where('id', $root->id)->first(['lft', 'rgt']);
        $this->assertEquals($rootBoundsBefore, $rootBoundsAfter);
    }
}
