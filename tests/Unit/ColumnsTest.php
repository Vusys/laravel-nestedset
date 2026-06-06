<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
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
    #[Test]
    public function lft_constant_is_string_lft_matching_default_migration_column(): void
    {
        $this->assertSame('lft', Columns::LFT);
    }

    #[Test]
    public function rgt_constant_is_string_rgt_matching_default_migration_column(): void
    {
        $this->assertSame('rgt', Columns::RGT);
    }

    #[Test]
    public function parent_id_constant_is_string_parent_id_matching_default_migration_column(): void
    {
        $this->assertSame('parent_id', Columns::PARENT_ID);
    }

    #[Test]
    public function depth_constant_is_string_depth_matching_default_migration_column(): void
    {
        $this->assertSame('depth', Columns::DEPTH);
    }

    #[Test]
    public function all_returns_the_four_structural_columns_in_lft_rgt_parent_depth_order(): void
    {
        // Order matters: the composite index in nestedSet() blueprint
        // macro uses the same order, and changing it would invalidate
        // existing application migrations.
        $this->assertSame(['lft', 'rgt', 'parent_id', 'depth'], Columns::all());
    }
}
