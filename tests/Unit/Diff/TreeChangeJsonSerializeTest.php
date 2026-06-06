<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Diff;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Diff\TreeChange\Added;
use Vusys\NestedSet\Diff\TreeChange\Modified;
use Vusys\NestedSet\Diff\TreeChange\Moved;
use Vusys\NestedSet\Diff\TreeChange\Removed;

/**
 * Each `TreeChange\*` value object's `jsonSerialize()` shape — what
 * frontends and review UIs receive after `json_encode($diff)`. The
 * shape is part of the public contract per design §10.
 */
final class TreeChangeJsonSerializeTest extends TestCase
{
    #[Test]
    public function added_serialises_with_all_fields(): void
    {
        $added = new Added(
            key: 7,
            parentKey: 3,
            attributes: ['name' => 'X'],
            siblingPosition: 1,
        );

        $this->assertSame([
            'type' => 'added',
            'key' => 7,
            'parentKey' => 3,
            'attributes' => ['name' => 'X'],
            'siblingPosition' => 1,
        ], $added->jsonSerialize());
    }

    #[Test]
    public function removed_serialises_with_round_trip_fields(): void
    {
        $removed = new Removed(
            key: 7,
            parentKey: null,
            attributes: ['name' => 'X'],
            siblingPosition: 2,
        );

        $this->assertSame([
            'type' => 'removed',
            'key' => 7,
            'parentKey' => null,
            'attributes' => ['name' => 'X'],
            'siblingPosition' => 2,
        ], $removed->jsonSerialize());
    }

    #[Test]
    public function moved_serialises_with_all_fields(): void
    {
        $moved = new Moved(
            key: 7,
            fromParent: 1,
            toParent: 2,
            toSiblingPosition: 3,
        );

        $this->assertSame([
            'type' => 'moved',
            'key' => 7,
            'fromParent' => 1,
            'toParent' => 2,
            'toSiblingPosition' => 3,
        ], $moved->jsonSerialize());
    }

    #[Test]
    public function modified_serialises_with_before_and_after(): void
    {
        $mod = new Modified(
            key: 7,
            before: ['name' => 'Old'],
            after: ['name' => 'New'],
        );

        $this->assertSame([
            'type' => 'modified',
            'key' => 7,
            'before' => ['name' => 'Old'],
            'after' => ['name' => 'New'],
        ], $mod->jsonSerialize());
    }
}
