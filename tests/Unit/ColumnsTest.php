<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Columns;

/**
 * Pins the *exact* string values of the structural-column constants
 * that the migrations, builders, and config defaults all depend on.
 * Renaming these would cascade silently — these tests fail loudly when
 * a constant value drifts away from the documented schema.
 */
final class ColumnsTest extends TestCase
{
    public function test_lft_constant_is_string_lft_matching_default_migration_column(): void
    {
        $this->assertSame('lft', Columns::LFT);
    }

    public function test_rgt_constant_is_string_rgt_matching_default_migration_column(): void
    {
        $this->assertSame('rgt', Columns::RGT);
    }

    public function test_parent_id_constant_is_string_parent_id_matching_default_migration_column(): void
    {
        $this->assertSame('parent_id', Columns::PARENT_ID);
    }

    public function test_depth_constant_is_string_depth_matching_default_migration_column(): void
    {
        $this->assertSame('depth', Columns::DEPTH);
    }

    public function test_all_returns_the_four_structural_columns_in_lft_rgt_parent_depth_order(): void
    {
        // Order matters: the composite index in nestedSet() blueprint
        // macro uses the same order, and changing it would invalidate
        // existing application migrations.
        $this->assertSame(['lft', 'rgt', 'parent_id', 'depth'], Columns::all());
    }
}
