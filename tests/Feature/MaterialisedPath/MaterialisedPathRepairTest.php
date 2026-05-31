<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\MaterialisedPath;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Tests\Fixtures\Models\MultiPathCategory;
use Vusys\NestedSet\Tests\TestCase;

final class MaterialisedPathRepairTest extends TestCase
{
    public function test_fix_materialised_paths_rebuilds_a_corrupted_column(): void
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

    public function test_fix_tree_rebuilds_paths_as_part_of_the_pass(): void
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
}
