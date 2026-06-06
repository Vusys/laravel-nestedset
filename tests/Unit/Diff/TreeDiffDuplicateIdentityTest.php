<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Diff;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Diff\TreeDiff;
use Vusys\NestedSet\Exceptions\DuplicateNodeIdentityException;

final class TreeDiffDuplicateIdentityTest extends TestCase
{
    #[Test]
    public function duplicate_in_before_throws(): void
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

    #[Test]
    public function duplicate_in_after_throws(): void
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
