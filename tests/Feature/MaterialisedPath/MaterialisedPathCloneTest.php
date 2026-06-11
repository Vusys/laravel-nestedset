<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\MaterialisedPath;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Exceptions\DuplicatePathSegment;
use Vusys\NestedSet\Tests\Fixtures\Models\SluggedCategory;
use Vusys\NestedSet\Tests\TestCase;

final class MaterialisedPathCloneTest extends TestCase
{
    #[Test]
    public function clone_subtree_regenerates_paths_under_new_parent(): void
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

    #[Test]
    public function cloning_under_the_same_parent_throws_on_path_collision(): void
    {
        // Source is a child of Root; cloning it back under Root would
        // produce a second '/root/source/' sibling. A normal save()
        // throws DuplicatePathSegment for this — clone must too, and the
        // whole clone must roll back (no orphaned rows left behind).
        $root = new SluggedCategory(['name' => 'Root']);
        $root->makeRoot()->save();

        $source = new SluggedCategory(['name' => 'Source']);
        $source->appendToNode($root->refresh())->save();
        $source->refresh();

        $before = SluggedCategory::query()->count();

        try {
            $source->cloneSubtreeTo($root->refresh());
            $this->fail('expected DuplicatePathSegment');
        } catch (DuplicatePathSegment $e) {
            $this->assertSame('url_path', $e->column);
        }

        $this->assertSame($before, SluggedCategory::query()->count(), 'failed clone must roll back fully');
        $this->assertFalse(SluggedCategory::isBroken());
    }

    #[Test]
    public function transform_setting_a_path_column_throws(): void
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
