<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\MaterialisedPath;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Exceptions\DuplicatePathSegmentException;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\Fixtures\Models\KeyPathCategory;
use Vusys\NestedSet\Tests\Fixtures\Models\SluggedCategory;
use Vusys\NestedSet\Tests\TestCase;

final class MaterialisedPathListenerBranchesTest extends TestCase
{
    #[Test]
    public function materialised_path_for_returns_value_object_for_declared_column(): void
    {
        $node = new SluggedCategory(['name' => 'X']);
        $path = $node->materialisedPathFor('url_path');
        $this->assertSame('name', $path->sourceColumn());
        $this->assertSame('/', $path->getSeparator());
    }

    #[Test]
    public function models_with_no_declared_paths_save_without_listener_work(): void
    {
        // Exercises the early-return branches in both saving and saved
        // listeners (Category declares no path columns).
        $root = new Category(['name' => 'Root']);
        $root->makeRoot()->save();

        $child = new Category(['name' => 'Child']);
        $child->appendToNode($root)->save();

        $this->assertSame(2, Category::query()->count());
    }

    #[Test]
    public function determinism_guard_double_call_passes_for_deterministic_builder(): void
    {
        // app.debug=true exercises the guard; the deterministic slug source
        // returns the same value on repeated calls so unset($second) runs.
        config(['app.debug' => true]);

        $node = new SluggedCategory(['name' => 'Stable']);
        $node->makeRoot()->save();
        $node->refresh();

        $this->assertSame('/stable/', $node->url_path);

        config(['app.debug' => false]);
    }

    #[Test]
    public function root_unique_per_parent_collision_detected_with_null_parent_id(): void
    {
        $a = new SluggedCategory(['name' => 'Root']);
        $a->makeRoot()->save();

        $b = new SluggedCategory(['name' => 'root']);

        $this->expectException(DuplicatePathSegmentException::class);
        $this->expectExceptionMessageMatches('/under parent \(root\)/');

        $b->makeRoot()->save();
    }

    #[Test]
    public function key_dependent_path_skipped_when_column_already_set(): void
    {
        // Force a key-dependent column to already have a value before
        // save, then ensure the saved listener leaves it alone.
        $root = new KeyPathCategory(['name' => 'R']);
        $root->makeRoot()->save();
        $root->refresh();
        $first = $root->id_path;

        // No actual change but a re-save should not re-write the path.
        $root->touch();
        $root->refresh();
        $this->assertSame($first, $root->id_path);
    }
}
