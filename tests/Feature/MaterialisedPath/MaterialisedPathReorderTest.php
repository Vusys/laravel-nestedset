<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\MaterialisedPath;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\SluggedCategory;
use Vusys\NestedSet\Tests\TestCase;

final class MaterialisedPathReorderTest extends TestCase
{
    #[Test]
    public function reorder_does_not_change_any_path(): void
    {
        $root = new SluggedCategory(['name' => 'Root']);
        $root->makeRoot()->save();

        $a = new SluggedCategory(['name' => 'A']);
        $a->appendToNode($root)->save();
        $b = new SluggedCategory(['name' => 'B']);
        $b->appendToNode($root)->save();
        $c = new SluggedCategory(['name' => 'C']);
        $c->appendToNode($root)->save();

        $a->refresh();
        $b->refresh();
        $c->refresh();
        $pre = [$a->url_path, $b->url_path, $c->url_path];

        $root->refresh();
        $root->reorderChildren([$c->id, $a->id, $b->id]);

        $a->refresh();
        $b->refresh();
        $c->refresh();
        $post = [$a->url_path, $b->url_path, $c->url_path];

        $this->assertSame($pre, $post, 'sibling reorder must not touch path columns');
    }
}
