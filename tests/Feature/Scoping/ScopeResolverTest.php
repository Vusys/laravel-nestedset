<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Scoping;

use Illuminate\Support\Facades\Date;
use Stringable;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\Fixtures\Models\ScopedArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Direct tests for {@see NestedSetScopeResolver}. ScopedArea declares
 * its scope via the method form (`getScopeAttributes()`), so it drives
 * the method branch of `columns()` that the attribute-form fixtures
 * (MenuItem) never reach. The value-comparison cases pin the resolver's
 * deliberate type permissiveness — mixed-type scope values that
 * represent the same partition must compare equal.
 */
final class ScopeResolverTest extends TestCase
{
    public function test_columns_reads_the_method_form_scope_declaration(): void
    {
        $this->assertSame(['tenant_id'], NestedSetScopeResolver::columns(ScopedArea::class));
    }

    public function test_values_for_maps_each_scope_column_to_its_attribute(): void
    {
        $node = new ScopedArea(['tenant_id' => 7, 'name' => 'x', 'amount' => 0]);

        $this->assertSame(['tenant_id' => 7], NestedSetScopeResolver::valuesFor($node));
    }

    public function test_same_scope_is_false_for_different_model_classes(): void
    {
        $area = new ScopedArea(['tenant_id' => 1, 'name' => 'a', 'amount' => 0]);
        $menuItem = new MenuItem(['menu_id' => 1, 'name' => 'b']);

        $this->assertFalse(NestedSetScopeResolver::sameScope($area, $menuItem));
    }

    public function test_same_scope_compares_datetime_scopes_by_instant(): void
    {
        $a = new ScopedArea(['name' => 'a', 'amount' => 0]);
        $a->setAttribute('tenant_id', Date::parse('2020-01-01 12:00:00'));

        $sameInstant = new ScopedArea(['name' => 'b', 'amount' => 0]);
        $sameInstant->setAttribute('tenant_id', Date::parse('2020-01-01 12:00:00'));

        $differentInstant = new ScopedArea(['name' => 'c', 'amount' => 0]);
        $differentInstant->setAttribute('tenant_id', Date::parse('2020-01-02 12:00:00'));

        $this->assertTrue(NestedSetScopeResolver::sameScope($a, $sameInstant));
        $this->assertFalse(NestedSetScopeResolver::sameScope($a, $differentInstant));
    }

    public function test_same_scope_uses_loose_equality_for_stringable_scopes(): void
    {
        $stringable = new class implements Stringable
        {
            public function __toString(): string
            {
                return 'tenant-x';
            }
        };

        $a = new ScopedArea(['name' => 'a', 'amount' => 0]);
        $a->setAttribute('tenant_id', $stringable);

        $b = new ScopedArea(['name' => 'b', 'amount' => 0]);
        $b->setAttribute('tenant_id', 'tenant-x');

        $this->assertTrue(NestedSetScopeResolver::sameScope($a, $b));
    }
}
