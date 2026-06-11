<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Scoping;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;
use Vusys\NestedSet\Tests\Fixtures\Models\MultiScopedBranch;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Scope-equality must not collapse distinct 64-bit integers (snowflake
 * IDs) through a float cast. Two different keys above 2^53 round to the
 * same double, which would let assertSameScope pass and a cross-tree
 * append corrupt both trees.
 */
final class ScopeSnowflakeEqualityTest extends TestCase
{
    #[Test]
    public function distinct_snowflake_scopes_are_not_equal(): void
    {
        // 2^53 and 2^53 + 1 are distinct integers but the same double.
        $a = new MultiScopedBranch(['name' => 'A', 'tenant_id' => 1, 'site_id' => 1]);
        $a->setAttribute('tenant_id', '9007199254740992');

        $b = new MultiScopedBranch(['name' => 'B', 'tenant_id' => 1, 'site_id' => 1]);
        $b->setAttribute('tenant_id', '9007199254740993');

        $this->assertFalse(
            NestedSetScopeResolver::sameScope($a, $b),
            'snowflake scope values one apart must not collapse to the same tree',
        );
    }

    #[Test]
    public function matching_scopes_across_int_and_string_remain_equal(): void
    {
        $a = new MultiScopedBranch(['name' => 'A', 'tenant_id' => 1, 'site_id' => 1]);
        $a->setAttribute('tenant_id', 42);

        $b = new MultiScopedBranch(['name' => 'B', 'tenant_id' => 1, 'site_id' => 1]);
        $b->setAttribute('tenant_id', '42');

        $this->assertTrue(
            NestedSetScopeResolver::sameScope($a, $b),
            'int 42 and string "42" must still count as the same scope',
        );
    }
}
