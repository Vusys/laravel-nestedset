<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Query;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Columns;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Runtime read side of `nestedset.columns.*` config overrides.
 *
 * Each `*Column()` accessor on `TreeQueryBuilder` reads from
 * `config('nestedset.columns.*')` and falls back to the {@see Columns}
 * constant when the config value isn't a string. The Schema/macro
 * tests verify the migration honours overrides; these tests pin the
 * runtime read — the ternary in each accessor returns the override
 * when set and the constant when absent.
 *
 * Each override test sets `$allowBrokenTreeAtTearDown = true` because
 * the integrity check in tearDown would otherwise look at the wrong
 * column on the empty fixture table.
 */
final class ColumnNameResolutionTest extends TestCase
{
    #[Test]
    public function lft_column_returns_default_constant_when_no_config_override(): void
    {
        $this->assertSame(Columns::LFT, Category::query()->lftColumn());
    }

    #[Test]
    public function lft_column_returns_config_override_when_set(): void
    {
        $this->allowBrokenTreeAtTearDown = true;
        config(['nestedset.columns.lft' => 'left_bound']);

        $this->assertSame('left_bound', Category::query()->lftColumn());
    }

    #[Test]
    public function rgt_column_returns_default_constant_when_no_config_override(): void
    {
        $this->assertSame(Columns::RGT, Category::query()->rgtColumn());
    }

    #[Test]
    public function rgt_column_returns_config_override_when_set(): void
    {
        $this->allowBrokenTreeAtTearDown = true;
        config(['nestedset.columns.rgt' => 'right_bound']);

        $this->assertSame('right_bound', Category::query()->rgtColumn());
    }

    #[Test]
    public function parent_id_column_returns_default_constant_when_no_config_override(): void
    {
        $this->assertSame(Columns::PARENT_ID, Category::query()->parentIdColumn());
    }

    #[Test]
    public function parent_id_column_returns_config_override_when_set(): void
    {
        $this->allowBrokenTreeAtTearDown = true;
        config(['nestedset.columns.parent_id' => 'node_parent']);

        $this->assertSame('node_parent', Category::query()->parentIdColumn());
    }

    #[Test]
    public function depth_column_returns_default_constant_when_no_config_override(): void
    {
        $this->assertSame(Columns::DEPTH, Category::query()->depthColumn());
    }

    #[Test]
    public function depth_column_returns_config_override_when_set(): void
    {
        $this->allowBrokenTreeAtTearDown = true;
        config(['nestedset.columns.depth' => 'node_depth']);

        $this->assertSame('node_depth', Category::query()->depthColumn());
    }
}
