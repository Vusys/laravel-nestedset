<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\MaterialisedPath;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Exceptions\DuplicatePathSegmentException;
use Vusys\NestedSet\Exceptions\EmptyPathSegmentException;
use Vusys\NestedSet\Exceptions\InvalidPathSegmentException;
use Vusys\NestedSet\Tests\Fixtures\Models\MultiPathCategory;
use Vusys\NestedSet\Tests\Fixtures\Models\SluggedCategory;
use Vusys\NestedSet\Tests\TestCase;

final class MaterialisedPathValidationTest extends TestCase
{
    #[Test]
    public function empty_segment_throws(): void
    {
        $this->expectException(EmptyPathSegmentException::class);
        $this->expectExceptionMessageMatches('/empty string/');

        $node = new SluggedCategory(['name' => '   ']);
        $node->makeRoot()->save();
    }

    #[Test]
    public function duplicate_sibling_throws(): void
    {
        $root = new SluggedCategory(['name' => 'Root']);
        $root->makeRoot()->save();

        $a = new SluggedCategory(['name' => 'Apples']);
        $a->appendToNode($root)->save();

        $this->expectException(DuplicatePathSegmentException::class);
        $this->expectExceptionMessageMatches('/sibling already holds path/');

        $b = new SluggedCategory(['name' => 'apples']);
        $b->appendToNode($root)->save();
    }

    #[Test]
    public function separator_in_segment_throws_by_default(): void
    {
        $this->expectException(InvalidPathSegmentException::class);

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
