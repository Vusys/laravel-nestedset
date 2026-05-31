<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\MaterialisedPath;

use Vusys\NestedSet\Tests\Fixtures\Models\KeyPathCategory;
use Vusys\NestedSet\Tests\TestCase;

final class MaterialisedPathKeyBasedInsertionTest extends TestCase
{
    public function test_key_path_is_set_after_insert(): void
    {
        $root = new KeyPathCategory(['name' => 'R']);
        $root->makeRoot()->save();
        $root->refresh();

        $rootId = $root->id;
        $expected = '.'.$rootId.'.';
        $this->assertSame($expected, $root->id_path);
    }

    public function test_key_path_for_child_includes_parent_chain(): void
    {
        $root = new KeyPathCategory(['name' => 'R']);
        $root->makeRoot()->save();
        $root->refresh();

        $child = new KeyPathCategory(['name' => 'C']);
        $child->appendToNode($root)->save();
        $child->refresh();

        $rootId = $root->id;
        $childId = $child->id;
        $expected = '.'.$rootId.'.'.$childId.'.';
        $this->assertSame($expected, $child->id_path);
    }
}
