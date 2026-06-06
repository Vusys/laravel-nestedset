<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Attributes;

use Attribute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Vusys\NestedSet\Attributes\NestedSetScope;

final class NestedSetScopeTest extends TestCase
{
    #[Test]
    public function accepts_single_column_name(): void
    {
        $scope = new NestedSetScope('tenant_id');

        $this->assertSame('tenant_id', $scope->columns);
    }

    #[Test]
    public function accepts_array_of_column_names(): void
    {
        $scope = new NestedSetScope(['tenant_id', 'site_id']);

        $this->assertSame(['tenant_id', 'site_id'], $scope->columns);
    }

    #[Test]
    public function is_declared_as_a_class_level_attribute(): void
    {
        $reflection = new ReflectionClass(NestedSetScope::class);
        $attributes = $reflection->getAttributes(Attribute::class);

        $this->assertCount(1, $attributes);

        /** @var Attribute $attr */
        $attr = $attributes[0]->newInstance();

        $this->assertSame(Attribute::TARGET_CLASS, $attr->flags);
    }
}
