<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Diff;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Diff\TreeDiff;
use Vusys\NestedSet\Exceptions\DuplicateNodeIdentityException;

/**
 * Errors specific to the nested-form path of `normalise()`: duplicate
 * identity inside a nested payload, and a parent that disappears mid-walk.
 */
final class TreeDiffNestedFormErrorsTest extends TestCase
{
    public function test_duplicate_identity_in_nested_input_throws(): void
    {
        $this->expectException(DuplicateNodeIdentityException::class);
        TreeDiff::between(
            [],
            [
                ['id' => 1, 'children' => [
                    ['id' => 2, 'children' => []],
                    ['id' => 2, 'children' => []],
                ]],
            ],
        );
    }
}
