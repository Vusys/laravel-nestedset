<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Query;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * withDepth()/whereIsLeaf() interpolate identifiers into raw SQL; without
 * grammar wrapping a reserved-word alias or custom column produces a
 * syntax error.
 */
final class ReservedIdentifierTest extends TestCase
{
    #[Test]
    public function with_depth_wraps_a_reserved_word_alias(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $child = new Category(['name' => 'Child']);
        $child->appendToNode($root->refresh())->save();

        // `order` is a reserved word — without wrapping `as order` is a
        // syntax error.
        $depths = Category::query()->withDepth('order')->defaultOrder()->pluck('order')->all();

        $this->assertEquals([0, 1], $depths);
    }

    #[Test]
    public function where_is_leaf_runs(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $child = new Category(['name' => 'Child']);
        $child->appendToNode($root->refresh())->save();

        $leaves = Category::query()->whereIsLeaf()->pluck('name')->all();
        $this->assertSame(['Child'], $leaves);
    }
}
