<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Mutation\Reorder;

use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
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

    #[Test]
    public function position_zero_is_first(): void
    {
        Category::query()->findOrFail(5)->moveToSiblingPosition(0);

        $this->assertSame(
            ['D', 'A', 'B', 'C'],
            Category::query()->where('parent_id', 1)->orderBy('lft')->pluck('name')->all(),
        );
    }

    #[Test]
    public function last_index_is_last(): void
    {
        // 4 siblings → last 0-based index is 3.
        Category::query()->findOrFail(2)->moveToSiblingPosition(3);

        $this->assertSame(
            ['B', 'C', 'D', 'A'],
            Category::query()->where('parent_id', 1)->orderBy('lft')->pluck('name')->all(),
        );
    }

    #[Test]
    public function position_middle_inserts_correctly(): void
    {
        // A moves to 0-based index 2. Remaining siblings [B,C,D]; A
        // inserted at slot 2 → B,C,A,D.
        Category::query()->findOrFail(2)->moveToSiblingPosition(2);

        $this->assertSame(
            ['B', 'C', 'A', 'D'],
            Category::query()->where('parent_id', 1)->orderBy('lft')->pluck('name')->all(),
        );
    }

    #[Test]
    public function moving_to_current_position_is_silent_no_op(): void
    {
        // B is already at 0-based index 1.
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

        $b->moveToSiblingPosition(1);

        $this->assertSame(0, $sniffer->updates);
        $this->assertSame(
            ['A', 'B', 'C', 'D'],
            Category::query()->where('parent_id', 1)->orderBy('lft')->pluck('name')->all(),
        );
    }

    #[Test]
    public function position_equal_to_count_throws(): void
    {
        // 4 siblings → valid range is [0, 3]; index 4 is past the end.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('position must be in [0, 3]');

        Category::query()->findOrFail(2)->moveToSiblingPosition(4);
    }

    #[Test]
    public function position_past_end_throws(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('position must be in [0, 3]');

        Category::query()->findOrFail(2)->moveToSiblingPosition(99);
    }

    #[Test]
    public function negative_position_throws(): void
    {
        $this->expectException(LogicException::class);

        Category::query()->findOrFail(2)->moveToSiblingPosition(-1);
    }

    #[Test]
    public function root_throws_because_no_parent(): void
    {
        $this->expectException(UnplacedNodeException::class);

        Category::query()->findOrFail(1)->moveToSiblingPosition(1);
    }

    #[Test]
    public function throws_when_parent_id_points_to_missing_row(): void
    {
        // Defensive guard: parent row vanished between this->load() and the
        // newQuery()->whereKey($parentId)->first() lookup. Reproduce by
        // pointing parent_id at a non-existent id via raw UPDATE.
        $this->allowBrokenTreeAtTearDown = true;
        DB::table('categories')->where('id', 2)->update(['parent_id' => 999]);

        $orphan = Category::query()->findOrFail(2);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('parent (id=999) not found');

        $orphan->moveToSiblingPosition(1);
    }
}
