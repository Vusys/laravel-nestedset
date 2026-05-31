<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\MaterialisedPath;

use Vusys\NestedSet\Tests\Fixtures\Models\MultiPathCategory;
use Vusys\NestedSet\Tests\TestCase;

final class MaterialisedPathMultiTest extends TestCase
{
    public function test_two_paths_maintain_independently(): void
    {
        $root = new MultiPathCategory(['name' => 'Electronics', 'display_name' => 'Electronics']);
        $root->makeRoot()->save();

        $child = new MultiPathCategory(['name' => 'Laptops', 'display_name' => 'Laptops & Notebooks']);
        $child->appendToNode($root)->save();
        $child->refresh();

        $this->assertSame('/electronics/laptops/', $child->url_path);
        $this->assertSame('Electronics > Laptops & Notebooks', $child->crumb_path);
    }

    public function test_renaming_one_source_only_changes_the_relevant_path(): void
    {
        $root = new MultiPathCategory(['name' => 'Electronics', 'display_name' => 'Electronics']);
        $root->makeRoot()->save();

        $child = new MultiPathCategory(['name' => 'Laptops', 'display_name' => 'Laptops']);
        $child->appendToNode($root)->save();
        $child->refresh();

        $originalUrl = $child->url_path;

        $child->display_name = 'Notebooks';
        $child->save();
        $child->refresh();

        $this->assertSame($originalUrl, $child->url_path, 'url_path is unaffected by display_name change');
        $this->assertSame('Electronics > Notebooks', $child->crumb_path);
    }
}
