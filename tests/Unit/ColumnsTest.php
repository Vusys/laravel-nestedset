<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Columns;

final class ColumnsTest extends TestCase
{
    public function test_lft_constant(): void
    {
        $this->assertSame('lft', Columns::LFT);
    }

    public function test_rgt_constant(): void
    {
        $this->assertSame('rgt', Columns::RGT);
    }

    public function test_parent_id_constant(): void
    {
        $this->assertSame('parent_id', Columns::PARENT_ID);
    }

    public function test_depth_constant(): void
    {
        $this->assertSame('depth', Columns::DEPTH);
    }

    public function test_all_returns_all_four_columns_in_order(): void
    {
        $this->assertSame(['lft', 'rgt', 'parent_id', 'depth'], Columns::all());
    }
}
