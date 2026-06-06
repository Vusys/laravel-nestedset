<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\MaterialisedPath;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\SluggedCategory;
use Vusys\NestedSet\Tests\TestCase;

final class MaterialisedPathRenameTest extends TestCase
{
    #[Test]
    public function rename_internal_node_rewrites_descendant_prefixes(): void
    {
        $root = new SluggedCategory(['name' => 'Electronics']);
        $root->makeRoot()->save();

        $child = new SluggedCategory(['name' => 'Laptops']);
        $child->appendToNode($root)->save();

        $grand = new SluggedCategory(['name' => 'Ultrabooks']);
        $grand->appendToNode($child)->save();

        $child->name = 'Computers';
        $child->save();

        $child->refresh();
        $grand->refresh();

        $this->assertSame('/electronics/computers/', $child->url_path);
        $this->assertSame('/electronics/computers/ultrabooks/', $grand->url_path);
    }

    #[Test]
    public function rename_root_rewrites_all_descendants(): void
    {
        $root = new SluggedCategory(['name' => 'Electronics']);
        $root->makeRoot()->save();

        $child = new SluggedCategory(['name' => 'Laptops']);
        $child->appendToNode($root)->save();

        $root->name = 'Tech';
        $root->save();

        $root->refresh();
        $child->refresh();

        $this->assertSame('/tech/', $root->url_path);
        $this->assertSame('/tech/laptops/', $child->url_path);
    }
}
