<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\MaterialisedPath;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Exceptions\PathTooLongException;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\Fixtures\Models\ScopedSluggedMenuItem;
use Vusys\NestedSet\Tests\Fixtures\Models\SluggedCategory;
use Vusys\NestedSet\Tests\TestCase;

final class MaterialisedPathErrorPathsTest extends TestCase
{
    #[Test]
    public function materialised_path_for_unknown_column_throws(): void
    {
        $node = new SluggedCategory(['name' => 'Root']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not declare a materialised-path column/');

        $node->materialisedPathFor('nope');
    }

    #[Test]
    public function materialised_path_for_unknown_column_on_model_without_any_declared_lists_none(): void
    {
        $node = new Category(['name' => 'Root']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/\(none\)/');

        $node->materialisedPathFor('whatever');
    }

    #[Test]
    public function path_too_long_throws(): void
    {
        // Default maxLength is 1024; build a name long enough that
        // its slug + wrap blows past it.
        $name = str_repeat('a', 1100);

        $this->expectException(PathTooLongException::class);
        $this->expectExceptionMessageMatches('/exceeds the configured maxLength/');

        $node = new SluggedCategory(['name' => $name]);
        $node->makeRoot()->save();
    }

    #[Test]
    public function is_maintenance_bypassed_reflects_depth(): void
    {
        $this->assertFalse(SluggedCategory::isMaterialisedPathMaintenanceBypassed());

        SluggedCategory::withoutMaterialisedPathMaintenance(function (): void {
            $this->assertTrue(SluggedCategory::isMaterialisedPathMaintenanceBypassed());

            // Reentrant: a nested call stays bypassed.
            SluggedCategory::withoutMaterialisedPathMaintenance(function (): void {
                $this->assertTrue(SluggedCategory::isMaterialisedPathMaintenanceBypassed());
            });

            $this->assertTrue(SluggedCategory::isMaterialisedPathMaintenanceBypassed());
        });

        $this->assertFalse(SluggedCategory::isMaterialisedPathMaintenanceBypassed());
    }

    #[Test]
    public function fix_materialised_paths_unknown_column_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not declared/');

        SluggedCategory::fixMaterialisedPaths('nope');
    }

    #[Test]
    public function fix_materialised_paths_on_scoped_model_without_anchor_throws(): void
    {
        $this->expectException(ScopeViolationException::class);

        ScopedSluggedMenuItem::fixMaterialisedPaths();
    }

    #[Test]
    public function fix_materialised_paths_on_model_with_no_paths_returns_empty_array(): void
    {
        // Category declares no materialised-path columns.
        $result = Category::fixMaterialisedPaths();
        $this->assertSame([], $result);
    }

    #[Test]
    public function fix_materialised_paths_accepts_anchor_to_scope_the_repair(): void
    {
        $root = new SluggedCategory(['name' => 'Root']);
        $root->makeRoot()->save();
        $child = new SluggedCategory(['name' => 'Child']);
        $child->appendToNode($root)->save();
        $other = new SluggedCategory(['name' => 'Other']);
        $other->makeRoot()->save();

        \DB::table('slugged_categories')->where('id', $child->id)->update(['url_path' => '/wrong/']);
        \DB::table('slugged_categories')->where('id', $other->id)->update(['url_path' => '/also-wrong/']);

        $repaired = SluggedCategory::fixMaterialisedPaths(anchor: $root->refresh());
        $this->assertSame(['url_path' => 1], $repaired);

        $other->refresh();
        $this->assertSame('/also-wrong/', $other->url_path, 'anchor-scoped repair leaves sibling tree alone');
    }
}
