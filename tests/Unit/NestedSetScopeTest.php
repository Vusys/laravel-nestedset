<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use Attribute;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Vusys\NestedSet\Attributes\NestedSetScope;

final class NestedSetScopeTest extends TestCase
{
    public function test_accepts_single_column_name(): void
    {
        $scope = new NestedSetScope('tenant_id');

        $this->assertSame('tenant_id', $scope->columns);
    }

    public function test_accepts_array_of_column_names(): void
    {
        $scope = new NestedSetScope(['tenant_id', 'site_id']);

        $this->assertSame(['tenant_id', 'site_id'], $scope->columns);
    }

    public function test_is_declared_as_a_class_level_attribute(): void
    {
        $reflection = new ReflectionClass(NestedSetScope::class);
        $attributes = $reflection->getAttributes(Attribute::class);

        $this->assertCount(1, $attributes);

        /** @var Attribute $attr */
        $attr = $attributes[0]->newInstance();

        $this->assertSame(Attribute::TARGET_CLASS, $attr->flags);
    }
}
