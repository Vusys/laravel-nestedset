<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\MaterialisedPath;

use Vusys\NestedSet\Tests\Fixtures\Models\ClosurePathArticle;
use Vusys\NestedSet\Tests\TestCase;

final class MaterialisedPathClosureTest extends TestCase
{
    public function test_method_form_closure_path_assembles_correctly(): void
    {
        $root = new ClosurePathArticle(['title' => 'My First Post']);
        $root->makeRoot()->save();
        $root->refresh();
        $this->assertSame('/my-first-post/', $root->breadcrumb_path);

        $child = new ClosurePathArticle(['title' => 'Follow Up']);
        $child->appendToNode($root)->save();
        $child->refresh();
        $this->assertSame('/my-first-post/follow-up/', $child->breadcrumb_path);
    }
}
