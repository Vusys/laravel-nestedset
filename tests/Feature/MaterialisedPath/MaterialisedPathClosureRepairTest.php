<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\MaterialisedPath;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\ClosurePathArticle;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Closure-source paths (MaterialisedPath::from(...)) have no declared
 * source column. The repair walk used a narrow projection that left the
 * closure's dependent attributes (e.g. `title`) unloaded, so every row
 * produced an empty segment and was silently skipped — fixMaterialisedPaths
 * returned 0 and clones kept NULL paths. The walk must load all columns.
 */
final class MaterialisedPathClosureRepairTest extends TestCase
{
    #[Test]
    public function fix_materialised_paths_repairs_closure_source_paths(): void
    {
        $root = new ClosurePathArticle(['title' => 'My First Post']);
        $root->makeRoot()->save();

        $child = new ClosurePathArticle(['title' => 'Follow Up']);
        $child->appendToNode($root->refresh())->save();

        // Corrupt the stored path values directly.
        DB::table('closure_path_articles')->update(['breadcrumb_path' => '/garbage/']);

        $repaired = ClosurePathArticle::fixMaterialisedPaths();

        $this->assertGreaterThan(0, array_sum($repaired), 'repair must rebuild closure-source paths');
        $this->assertSame('/my-first-post/', $root->refresh()->breadcrumb_path);
        $this->assertSame('/my-first-post/follow-up/', $child->refresh()->breadcrumb_path);
    }

    #[Test]
    public function cloning_a_closure_path_subtree_rebuilds_paths(): void
    {
        $root = new ClosurePathArticle(['title' => 'My First Post']);
        $root->makeRoot()->save();

        $child = new ClosurePathArticle(['title' => 'Follow Up']);
        $child->appendToNode($root->refresh())->save();

        $target = new ClosurePathArticle(['title' => 'Archive']);
        $target->makeRoot()->save();

        $clone = $root->refresh()->cloneSubtreeTo($target->refresh());

        $this->assertSame('/archive/my-first-post/', $clone->refresh()->breadcrumb_path);
        $cloneChild = $clone->children()->firstOrFail();
        $this->assertSame('/archive/my-first-post/follow-up/', $cloneChild->breadcrumb_path);
    }
}
