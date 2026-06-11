<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\MaterialisedPath;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\MultiPathCategory;
use Vusys\NestedSet\Tests\TestCase;

final class MaterialisedPathRepairTest extends TestCase
{
    #[Test]
    public function fix_materialised_paths_rebuilds_a_corrupted_column(): void
    {
        $root = new MultiPathCategory(['name' => 'Electronics', 'display_name' => 'Electronics']);
        $root->makeRoot()->save();
        $child = new MultiPathCategory(['name' => 'Laptops', 'display_name' => 'Laptops']);
        $child->appendToNode($root)->save();
        $child->refresh();
        $this->assertSame('/electronics/laptops/', $child->url_path);

        // Corrupt the column directly.
        DB::table('multi_path_categories')->where('id', $child->id)->update(['url_path' => '/garbage/']);

        $repaired = MultiPathCategory::fixMaterialisedPaths('url_path');
        $this->assertArrayHasKey('url_path', $repaired);
        $this->assertGreaterThan(0, $repaired['url_path']);

        $child->refresh();
        $this->assertSame('/electronics/laptops/', $child->url_path);
    }

    #[Test]
    public function fix_tree_rebuilds_paths_as_part_of_the_pass(): void
    {
        $root = new MultiPathCategory(['name' => 'A', 'display_name' => 'A']);
        $root->makeRoot()->save();
        $child = new MultiPathCategory(['name' => 'B', 'display_name' => 'B']);
        $child->appendToNode($root)->save();

        DB::table('multi_path_categories')->where('id', $child->id)->update(['url_path' => '/wrong/']);

        $result = MultiPathCategory::fixTree();
        $this->assertArrayHasKey('url_path', $result->materialisedPathsRepaired);

        $child->refresh();
        $this->assertSame('/a/b/', $child->url_path);
    }

    #[Test]
    public function anchored_fix_tree_rebuilds_paths_against_fresh_anchor_bounds(): void
    {
        // The documented recovery: raw `UPDATE ... SET parent_id` then
        // an anchored fixTree(). The structural rebuild renumbers the
        // anchor's own bounds, so a path pass that bands on the stale
        // in-memory anchor bounds would cover the wrong rows and repair
        // nothing.
        $a = new MultiPathCategory(['name' => 'A', 'display_name' => 'A']);
        $a->makeRoot()->save();
        $b = new MultiPathCategory(['name' => 'B', 'display_name' => 'B']);
        $b->appendToNode($a->refresh())->save();
        $c = new MultiPathCategory(['name' => 'C', 'display_name' => 'C']);
        $c->appendToNode($a->refresh())->save();

        // Capture B as a stale instance: bounds (2,3) here, but after the
        // raw re-point + rebuild below its rgt grows to 5.
        $staleB = MultiPathCategory::query()->findOrFail($b->id);

        // Re-point C under B via raw SQL (parent_id source of truth) and
        // corrupt its path. Bounds now disagree with parent_id.
        DB::table('multi_path_categories')->where('id', $c->id)->update([
            'parent_id' => $b->id,
            'url_path' => '/garbage/',
        ]);

        $result = MultiPathCategory::fixTree($staleB);

        $this->assertArrayHasKey('url_path', $result->materialisedPathsRepaired);
        $this->assertGreaterThan(0, $result->materialisedPathsRepaired['url_path']);

        $c->refresh();
        $this->assertSame('/a/b/c/', $c->url_path);

        $this->assertSame(0, array_sum(MultiPathCategory::countErrors()));
    }
}
