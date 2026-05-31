<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Diff;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Diff\TreeDiff;
use Vusys\NestedSet\Exceptions\DuplicateNodeIdentityException;

final class TreeDiffDuplicateIdentityTest extends TestCase
{
    public function test_duplicate_in_before_throws(): void
    {
        $this->expectException(DuplicateNodeIdentityException::class);
        TreeDiff::between(
            [
                ['id' => 1, 'parent_id' => null],
                ['id' => 1, 'parent_id' => null],
            ],
            [],
        );
    }

    public function test_duplicate_in_after_throws(): void
    {
        $this->expectException(DuplicateNodeIdentityException::class);
        TreeDiff::between(
            [],
            [
                ['id' => 1, 'parent_id' => null],
                ['id' => 1, 'parent_id' => null],
            ],
        );
    }
}
