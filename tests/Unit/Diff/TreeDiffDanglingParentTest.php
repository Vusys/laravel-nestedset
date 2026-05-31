<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Diff;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Diff\TreeDiff;
use Vusys\NestedSet\Exceptions\DanglingParentException;

final class TreeDiffDanglingParentTest extends TestCase
{
    public function test_flat_form_dangling_parent_id_throws(): void
    {
        $this->expectException(DanglingParentException::class);
        TreeDiff::between(
            [],
            [
                ['id' => 1, 'name' => 'orphan', 'parent_id' => 999],
            ],
        );
    }
}
