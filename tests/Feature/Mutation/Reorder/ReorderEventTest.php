<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Mutation\Reorder;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Events\Mutation\SiblingsReordered;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * The SiblingsReordered event fires once per non-identity reorder with
 * the parent, the post-reorder ID order, and the row count touched by
 * the UPDATE. Identity reorders skip the event entirely (no UPDATE,
 * no event).
 */
final class ReorderEventTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Root (1..14)
        //   A (2..5)   AA (3..4)
        //   B (6..9)   BB (7..8)
        //   C (10..13) CC (11..12)
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root', 'lft' => 1,  'rgt' => 14, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'A',    'lft' => 2,  'rgt' => 5,  'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'AA',   'lft' => 3,  'rgt' => 4,  'depth' => 2, 'parent_id' => 2],
            ['id' => 4, 'name' => 'B',    'lft' => 6,  'rgt' => 9,  'depth' => 1, 'parent_id' => 1],
            ['id' => 5, 'name' => 'BB',   'lft' => 7,  'rgt' => 8,  'depth' => 2, 'parent_id' => 4],
            ['id' => 6, 'name' => 'C',    'lft' => 10, 'rgt' => 13, 'depth' => 1, 'parent_id' => 1],
            ['id' => 7, 'name' => 'CC',   'lft' => 11, 'rgt' => 12, 'depth' => 2, 'parent_id' => 6],
        ]);
        $this->syncSequence('categories');
    }

    #[Test]
    public function event_fires_once_with_expected_payload(): void
    {
        Event::fake([SiblingsReordered::class]);

        $root = Category::query()->findOrFail(1);
        $root->reorderChildren([6, 2, 4]);

        Event::assertDispatchedTimes(SiblingsReordered::class, 1);
        Event::assertDispatched(SiblingsReordered::class, static fn (SiblingsReordered $e): bool => $e->modelClass === Category::class
            && $e->parent->getKey() === 1
            && $e->idsInOrder === [6, 2, 4]
            && $e->rowsAffected === 6 // 3 siblings + 3 grandchildren
            && $e->durationMs >= 0.0);
    }

    #[Test]
    public function identity_reorder_emits_no_event(): void
    {
        Event::fake([SiblingsReordered::class]);

        Category::query()->findOrFail(1)->reorderChildren([2, 4, 6]);

        Event::assertNotDispatched(SiblingsReordered::class);
    }

    #[Test]
    public function empty_parent_emits_no_event(): void
    {
        Event::fake([SiblingsReordered::class]);

        Category::query()->findOrFail(3)->reorderChildren([]);

        Event::assertNotDispatched(SiblingsReordered::class);
    }
}
