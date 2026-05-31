<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\MaterialisedPath;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetMaterialisedPath;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\MaterialisedPathConfigurationException;
use Vusys\NestedSet\MaterialisedPath\MaterialisedPathRegistry;
use Vusys\NestedSet\NodeTrait;
use Vusys\NestedSet\Tests\TestCase;

#[NestedSetMaterialisedPath(column: 'p', slug: 'name')]
#[NestedSetMaterialisedPath(column: 'p', slug: 'title')]
final class DuplicateAttributeFixture extends Model implements HasNestedSet
{
    use NodeTrait;
}

final class MaterialisedPathDuplicateAttributeTest extends TestCase
{
    public function test_two_attributes_on_same_column_throw(): void
    {
        MaterialisedPathRegistry::forgetCache();

        $this->expectException(MaterialisedPathConfigurationException::class);
        $this->expectExceptionMessageMatches('/duplicate #\[NestedSetMaterialisedPath/');

        MaterialisedPathRegistry::for(DuplicateAttributeFixture::class);
    }
}
