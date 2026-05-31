<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\MaterialisedPath;

use Vusys\NestedSet\Tests\Fixtures\Models\SluggedCategory;
use Vusys\NestedSet\Tests\TestCase;

final class MaterialisedPathMoveTest extends TestCase
{
    public function test_move_subtree_rewrites_descendant_paths(): void
    {
        $a = $this->plant('A');
        $b = $this->plant('B');

        $aChild = $this->plant('A-Child', $a);
        $aGrand = $this->plant('A-Grand', $aChild);

        $aChild->refresh();
        $this->assertSame('/a/a-child/', $aChild->url_path);
        $aGrand->refresh();
        $this->assertSame('/a/a-child/a-grand/', $aGrand->url_path);

        $aChild->appendToNode($b->refresh())->save();
        $aChild->refresh();
        $aGrand->refresh();

        $this->assertSame('/b/a-child/', $aChild->url_path);
        $this->assertSame('/b/a-child/a-grand/', $aGrand->url_path);
    }

    public function test_move_does_not_touch_sibling_subtrees(): void
    {
        $a = $this->plant('A');
        $b = $this->plant('B');

        $aChild = $this->plant('A-Child', $a);
        $bChild = $this->plant('B-Child', $b);
        $bGrand = $this->plant('B-Grand', $bChild);

        $aChild->refresh();
        $aChild->appendToNode($b->refresh())->save();

        $bChild->refresh();
        $bGrand->refresh();
        $this->assertSame('/b/b-child/', $bChild->url_path);
        $this->assertSame('/b/b-child/b-grand/', $bGrand->url_path);
    }

    private function plant(string $name, ?SluggedCategory $parent = null): SluggedCategory
    {
        $node = new SluggedCategory(['name' => $name]);
        if (! $parent instanceof SluggedCategory) {
            $node->makeRoot();
        } else {
            $node->appendToNode($parent);
        }
        $node->save();

        return $node;
    }
}
