<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\MaterialisedPath;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Attributes\NestedSetMaterialisedPath;
use Vusys\NestedSet\Exceptions\MaterialisedPathConfigurationException;

final class MaterialisedPathAttributeResolutionTest extends TestCase
{
    #[Test]
    public function slug_source_resolves(): void
    {
        $attr = new NestedSetMaterialisedPath(column: 'url_path', slug: 'name');
        $path = $attr->toValueObject();
        $this->assertSame('name', $path->sourceColumn());
    }

    #[Test]
    public function attribute_source_resolves(): void
    {
        $attr = new NestedSetMaterialisedPath(column: 'url_path', attribute: 'name');
        $path = $attr->toValueObject();
        $this->assertSame('name', $path->sourceColumn());
    }

    #[Test]
    public function key_source_resolves(): void
    {
        $attr = new NestedSetMaterialisedPath(column: 'id_path', key: true);
        $path = $attr->toValueObject();
        $this->assertTrue($path->isDependentOnKey());
    }

    #[Test]
    public function no_source_throws(): void
    {
        $attr = new NestedSetMaterialisedPath(column: 'x');
        $this->expectException(MaterialisedPathConfigurationException::class);
        $attr->toValueObject();
    }

    #[Test]
    public function multiple_sources_throws(): void
    {
        $attr = new NestedSetMaterialisedPath(column: 'x', key: true, attribute: 'name');
        $this->expectException(MaterialisedPathConfigurationException::class);
        $attr->toValueObject();
    }

    #[Test]
    public function empty_column_throws(): void
    {
        $attr = new NestedSetMaterialisedPath(column: '', slug: 'name');
        $this->expectException(MaterialisedPathConfigurationException::class);
        $attr->toValueObject();
    }

    #[Test]
    public function formatting_options_carry_through(): void
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
