<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\MaterialisedPath;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Attributes\NestedSetMaterialisedPath;
use Vusys\NestedSet\Exceptions\MaterialisedPathConfigurationException;

final class MaterialisedPathAttributeResolutionTest extends TestCase
{
    public function test_slug_source_resolves(): void
    {
        $attr = new NestedSetMaterialisedPath(column: 'url_path', slug: 'name');
        $path = $attr->toValueObject();
        $this->assertSame('name', $path->sourceColumn());
    }

    public function test_attribute_source_resolves(): void
    {
        $attr = new NestedSetMaterialisedPath(column: 'url_path', attribute: 'name');
        $path = $attr->toValueObject();
        $this->assertSame('name', $path->sourceColumn());
    }

    public function test_key_source_resolves(): void
    {
        $attr = new NestedSetMaterialisedPath(column: 'id_path', key: true);
        $path = $attr->toValueObject();
        $this->assertTrue($path->isDependentOnKey());
    }

    public function test_no_source_throws(): void
    {
        $attr = new NestedSetMaterialisedPath(column: 'x');
        $this->expectException(MaterialisedPathConfigurationException::class);
        $attr->toValueObject();
    }

    public function test_multiple_sources_throws(): void
    {
        $attr = new NestedSetMaterialisedPath(column: 'x', key: true, attribute: 'name');
        $this->expectException(MaterialisedPathConfigurationException::class);
        $attr->toValueObject();
    }

    public function test_empty_column_throws(): void
    {
        $attr = new NestedSetMaterialisedPath(column: '', slug: 'name');
        $this->expectException(MaterialisedPathConfigurationException::class);
        $attr->toValueObject();
    }

    public function test_formatting_options_carry_through(): void
    {
        $attr = new NestedSetMaterialisedPath(
            column: 'p',
            attribute: 'name',
            separator: '.',
            wrap: false,
            maxLength: 256,
            rejectSeparatorInSegment: false,
            uniquePerParent: false,
        );
        $path = $attr->toValueObject();
        $this->assertSame('.', $path->getSeparator());
        $this->assertFalse($path->getWrap());
        $this->assertSame(256, $path->getMaxLength());
        $this->assertFalse($path->getRejectSeparatorInSegment());
        $this->assertFalse($path->getUniquePerParent());
    }
}
