<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Scope;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;
use Vusys\NestedSet\Tests\Fixtures\Models\ScopedBaseItem;
use Vusys\NestedSet\Tests\Fixtures\Models\ScopedSubItem;

/**
 * #[NestedSetScope] declared on a parent model must apply to subclasses —
 * ReflectionClass::getAttributes() does not traverse parents, so without
 * the resolver's parent-chain walk a subclass would silently resolve to
 * no scope and leak mutations across trees.
 */
final class InheritedScopeTest extends TestCase
{
    #[Test]
    public function scope_attribute_is_inherited_by_subclasses(): void
    {
        $this->assertSame(['menu_id'], NestedSetScopeResolver::columns(ScopedBaseItem::class));
        $this->assertSame(['menu_id'], NestedSetScopeResolver::columns(ScopedSubItem::class));
    }
}
