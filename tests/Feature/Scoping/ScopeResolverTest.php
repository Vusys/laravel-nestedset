<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Scoping;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Stringable;
use Vusys\NestedSet\Events\Diagnostics\ScopeViolationDetected;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
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

    public function test_same_scope_treats_int_and_numeric_string_as_equal(): void
    {
        // tenant_id is uncast, so these keep their PHP types. The numeric
        // branch must normalise `int 5` and `string '5'` to the same
        // partition via float coercion — dropping either `(float)` cast
        // turns the comparison into a strict int-vs-float mismatch.
        $a = new ScopedArea(['tenant_id' => 5, 'name' => 'a', 'amount' => 0]);
        $b = new ScopedArea(['tenant_id' => '5', 'name' => 'b', 'amount' => 0]);

        $this->assertSame(5, $a->getAttribute('tenant_id'));
        $this->assertSame('5', $b->getAttribute('tenant_id'));
        $this->assertTrue(NestedSetScopeResolver::sameScope($a, $b));
    }

    public function test_same_scope_does_not_collapse_numeric_prefixed_strings(): void
    {
        // `'1abc'` is not numeric, so the numeric branch must NOT fire —
        // both operands have to be numeric. Were the guard relaxed to
        // `||` (or either operand's `is_numeric` negated) the float
        // coercion would read `'1abc'` as `1.0` and wrongly merge the
        // partitions. Asserted in both directions to pin each operand's
        // half of the conjunction.
        $numeric = new ScopedArea(['tenant_id' => 1, 'name' => 'a', 'amount' => 0]);
        $prefixed = new ScopedArea(['tenant_id' => '1abc', 'name' => 'b', 'amount' => 0]);

        $this->assertFalse(NestedSetScopeResolver::sameScope($numeric, $prefixed));
        $this->assertFalse(NestedSetScopeResolver::sameScope($prefixed, $numeric));
    }

    public function test_same_scope_treats_two_null_scopes_as_the_same_partition(): void
    {
        // The identity fast-path (`$a === $b`) is what makes null/null
        // resolve as the same partition before the null-guard returns
        // false. Removing it would flip two unscoped nodes to "different
        // scope".
        $a = new ScopedArea(['name' => 'a', 'amount' => 0]);
        $a->setAttribute('tenant_id', null);

        $b = new ScopedArea(['name' => 'b', 'amount' => 0]);
        $b->setAttribute('tenant_id', null);

        $this->assertNull($a->getAttribute('tenant_id'));
        $this->assertTrue(NestedSetScopeResolver::sameScope($a, $b));
    }

    public function test_assert_same_scope_dispatches_mutation_violation_with_formatted_message(): void
    {
        Event::fake([ScopeViolationDetected::class]);

        $a = new ScopedArea(['tenant_id' => 1, 'name' => 'a', 'amount' => 0]);
        $b = new ScopedArea(['tenant_id' => 2, 'name' => 'b', 'amount' => 0]);

        try {
            NestedSetScopeResolver::assertSameScope($a, $b);
            $this->fail('expected ScopeViolationException');
        } catch (ScopeViolationException $e) {
            // The column name and both formatted values must appear,
            // pinning format() — a null-check flip, an is_scalar flip, or
            // dropping the string cast would each replace `1`/`2` with
            // `null`/`int`/empty in the message.
            $this->assertStringContainsString('tenant_id', $e->getMessage());
            $this->assertStringContainsString('(1 vs 2)', $e->getMessage());
        }

        // The observability event must fire on the mutation-stage path
        // (the existing repair-stage test does not reach assertSameScope).
        Event::assertDispatched(ScopeViolationDetected::class, function (ScopeViolationDetected $event): bool {
            $this->assertSame(ScopedArea::class, $event->modelClass);
            $this->assertSame('mutation', $event->stage);
            $this->assertStringContainsString('(1 vs 2)', $event->message);

            return true;
        });
    }

    public function test_assert_same_scope_formats_a_null_operand_as_null_in_the_message(): void
    {
        // A null scope value must render as the literal `null` in the
        // violation message — the dedicated null arm of format(), distinct
        // from the scalar cast arm exercised above.
        $a = new ScopedArea(['name' => 'a', 'amount' => 0]);
        $a->setAttribute('tenant_id', null);
        $b = new ScopedArea(['tenant_id' => 2, 'name' => 'b', 'amount' => 0]);

        $this->expectException(ScopeViolationException::class);
        $this->expectExceptionMessage('(null vs 2)');

        NestedSetScopeResolver::assertSameScope($a, $b);
    }
}
