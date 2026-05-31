<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Diff;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Diff\TreeDiff;
use Vusys\NestedSet\Exceptions\DuplicateNodeIdentityException;

/**
 * Errors specific to the nested-form path of `normalise()`: duplicate
 * identity inside a nested payload.
 *
 * The dangling-parent guard in the nested walk's `assertParentsResolve`
 * is unreachable through the public API — `walkNested` derives every
 * row's parent identity from its enclosing nesting position, so a
 * row's parent is always already in the output map by construction.
 * The guard stays as defensive code; no test reaches it.
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
