<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\MaterialisedPath;

use Vusys\NestedSet\Tests\Fixtures\Models\SluggedCategory;
use Vusys\NestedSet\Tests\TestCase;

final class MaterialisedPathInsertionTest extends TestCase
{
    public function test_root_path_wraps_segment(): void
    {
        $root = new SluggedCategory(['name' => 'Electronics']);
        $root->makeRoot()->save();

        $root->refresh();
        $this->assertSame('/electronics/', $root->url_path);
    }

    public function test_child_path_appends_to_parent(): void
    {
        $root = new SluggedCategory(['name' => 'Electronics']);
        $root->makeRoot()->save();

        $child = new SluggedCategory(['name' => 'Laptops']);
        $child->appendToNode($root)->save();
        $child->refresh();

        $this->assertSame('/electronics/laptops/', $child->url_path);
    }

    public function test_grandchild_path_assembles_full_chain(): void
    {
        $root = new SluggedCategory(['name' => 'Electronics']);
        $root->makeRoot()->save();

        $child = new SluggedCategory(['name' => 'Laptops']);
        $child->appendToNode($root)->save();

        $grandchild = new SluggedCategory(['name' => 'Ultrabooks']);
        $grandchild->appendToNode($child)->save();
        $grandchild->refresh();

        $this->assertSame('/electronics/laptops/ultrabooks/', $grandchild->url_path);
    }

    public function test_unchanged_save_does_not_rewrite_path(): void
    {
        $root = new SluggedCategory(['name' => 'Electronics']);
        $root->makeRoot()->save();
        $root->refresh();
        $original = $root->url_path;

        $root->touch();
        $root->refresh();
        $this->assertSame($original, $root->url_path);
    }
}
