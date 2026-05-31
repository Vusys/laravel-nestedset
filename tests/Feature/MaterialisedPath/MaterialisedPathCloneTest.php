<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\MaterialisedPath;

use LogicException;
use Vusys\NestedSet\Tests\Fixtures\Models\SluggedCategory;
use Vusys\NestedSet\Tests\TestCase;

final class MaterialisedPathCloneTest extends TestCase
{
    public function test_clone_subtree_regenerates_paths_under_new_parent(): void
    {
        $source = new SluggedCategory(['name' => 'Source']);
        $source->makeRoot()->save();

        $child = new SluggedCategory(['name' => 'Child']);
        $child->appendToNode($source)->save();

        $destination = new SluggedCategory(['name' => 'Dest']);
        $destination->makeRoot()->save();

        $clone = $source->cloneSubtreeTo($destination->refresh());
        $clone->refresh();

        $this->assertSame('/dest/source/', $clone->url_path);

        $clonedChild = SluggedCategory::query()
            ->where('parent_id', $clone->getKey())
            ->first();
        $this->assertNotNull($clonedChild);
        $this->assertSame('/dest/source/child/', $clonedChild->url_path);
    }

    public function test_transform_setting_a_path_column_throws(): void
    {
        $source = new SluggedCategory(['name' => 'Source']);
        $source->makeRoot()->save();

        $destination = new SluggedCategory(['name' => 'Dest']);
        $destination->makeRoot()->save();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/reserved column/');

        $source->cloneSubtreeTo(
            $destination->refresh(),
            transform: static fn (array $attrs): array => $attrs + ['url_path' => '/forced/'],
        );
    }
}
