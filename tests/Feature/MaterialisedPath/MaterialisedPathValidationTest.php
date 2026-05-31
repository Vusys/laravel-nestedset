<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\MaterialisedPath;

use Vusys\NestedSet\Exceptions\DuplicatePathSegment;
use Vusys\NestedSet\Exceptions\EmptyPathSegment;
use Vusys\NestedSet\Exceptions\InvalidPathSegment;
use Vusys\NestedSet\Tests\Fixtures\Models\MultiPathCategory;
use Vusys\NestedSet\Tests\Fixtures\Models\SluggedCategory;
use Vusys\NestedSet\Tests\TestCase;

final class MaterialisedPathValidationTest extends TestCase
{
    public function test_empty_segment_throws(): void
    {
        $this->expectException(EmptyPathSegment::class);
        $this->expectExceptionMessageMatches('/empty string/');

        $node = new SluggedCategory(['name' => '   ']);
        $node->makeRoot()->save();
    }

    public function test_duplicate_sibling_throws(): void
    {
        $root = new SluggedCategory(['name' => 'Root']);
        $root->makeRoot()->save();

        $a = new SluggedCategory(['name' => 'Apples']);
        $a->appendToNode($root)->save();

        $this->expectException(DuplicatePathSegment::class);
        $this->expectExceptionMessageMatches('/sibling already holds path/');

        $b = new SluggedCategory(['name' => 'apples']);
        $b->appendToNode($root)->save();
    }

    public function test_separator_in_segment_throws_by_default(): void
    {
        $this->expectException(InvalidPathSegment::class);

        // Slugify is run by the source kind, but the produced slug then
        // gets passed through validation. A name that already contains
        // forward-slashes would be slugified away — instead use the
        // closure-form fixture to reach the validation path.
        // Use the attribute form on MultiPath instead — its crumb_path
        // is an attribute source with separator " > ", and a name
        // containing " > " triggers the check.
        $root = new MultiPathCategory([
            'name' => 'root',
            'display_name' => 'A > Bad',
        ]);
        $root->makeRoot()->save();
    }
}
