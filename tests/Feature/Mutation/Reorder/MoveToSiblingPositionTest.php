<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Mutation\Reorder;

use Illuminate\Support\Facades\DB;
use OutOfRangeException;
use Vusys\NestedSet\Exceptions\UnplacedNodeException;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Built tree:
 *   Root
 *     A
 *     B
 *     C
 *     D
 */
final class MoveToSiblingPositionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root', 'lft' => 1, 'rgt' => 10, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'A',    'lft' => 2, 'rgt' => 3,  'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'B',    'lft' => 4, 'rgt' => 5,  'depth' => 1, 'parent_id' => 1],
            ['id' => 4, 'name' => 'C',    'lft' => 6, 'rgt' => 7,  'depth' => 1, 'parent_id' => 1],
            ['id' => 5, 'name' => 'D',    'lft' => 8, 'rgt' => 9,  'depth' => 1, 'parent_id' => 1],
        ]);
        $this->syncSequence('categories');
    }

    public function test_position_one_is_first(): void
    {
        Category::query()->findOrFail(5)->moveToSiblingPosition(1);

        $this->assertSame(
            ['D', 'A', 'B', 'C'],
            Category::query()->where('parent_id', 1)->orderBy('lft')->pluck('name')->all(),
        );
    }

    public function test_position_last_is_last(): void
    {
        Category::query()->findOrFail(2)->moveToSiblingPosition(4);

        $this->assertSame(
            ['B', 'C', 'D', 'A'],
            Category::query()->where('parent_id', 1)->orderBy('lft')->pluck('name')->all(),
        );
    }

    public function test_position_middle_inserts_correctly(): void
    {
        Category::query()->findOrFail(2)->moveToSiblingPosition(3);

        // A goes from index 0 (1-indexed: position 1) to position 3.
        // Remaining siblings [B,C,D]; A inserted at slot 2 (0-indexed) → B,C,A,D.
        $this->assertSame(
            ['B', 'C', 'A', 'D'],
            Category::query()->where('parent_id', 1)->orderBy('lft')->pluck('name')->all(),
        );
    }

    public function test_moving_to_current_position_is_silent_no_op(): void
    {
        $b = Category::query()->findOrFail(3);

        $sniffer = new class
        {
            public int $updates = 0;
        };
        DB::listen(static function ($event) use ($sniffer): void {
            if (str_starts_with(ltrim(strtoupper((string) $event->sql)), 'UPDATE')) {
                $sniffer->updates++;
            }
        });

        $b->moveToSiblingPosition(2);

        $this->assertSame(0, $sniffer->updates);
        $this->assertSame(
            ['A', 'B', 'C', 'D'],
            Category::query()->where('parent_id', 1)->orderBy('lft')->pluck('name')->all(),
        );
    }

    public function test_position_zero_throws(): void
    {
        $this->expectException(OutOfRangeException::class);
        $this->expectExceptionMessage('position must be in [1, 4]');

        Category::query()->findOrFail(2)->moveToSiblingPosition(0);
    }

    public function test_position_past_end_throws(): void
    {
        $this->expectException(OutOfRangeException::class);
        $this->expectExceptionMessage('position must be in [1, 4]');

        Category::query()->findOrFail(2)->moveToSiblingPosition(99);
    }

    public function test_negative_position_throws(): void
    {
        $this->expectException(OutOfRangeException::class);

        Category::query()->findOrFail(2)->moveToSiblingPosition(-1);
    }

    public function test_root_throws_because_no_parent(): void
    {
        $this->expectException(UnplacedNodeException::class);

        Category::query()->findOrFail(1)->moveToSiblingPosition(1);
    }
}
