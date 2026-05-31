<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Mutation\Reorder;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Exceptions\UnplacedNodeException;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Built tree:
 *   Root
 *     Cherry
 *     Apple
 *     Banana
 */
final class ReorderChildrenByTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root',   'lft' => 1, 'rgt' => 8, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'Cherry', 'lft' => 2, 'rgt' => 3, 'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'Apple',  'lft' => 4, 'rgt' => 5, 'depth' => 1, 'parent_id' => 1],
            ['id' => 4, 'name' => 'Banana', 'lft' => 6, 'rgt' => 7, 'depth' => 1, 'parent_id' => 1],
        ]);
        $this->syncSequence('categories');
    }

    public function test_sorts_children_by_column_name(): void
    {
        Category::query()->findOrFail(1)->reorderChildrenBy('name');

        $this->assertSame(
            ['Apple', 'Banana', 'Cherry'],
            Category::query()->where('parent_id', 1)->orderBy('lft')->pluck('name')->all(),
        );
    }

    public function test_sorts_children_by_closure(): void
    {
        // Sort by name length descending using a closure that returns
        // a negative-length tie-broken-on-id sort key.
        Category::query()->findOrFail(1)->reorderChildrenBy(
            fn (Category $c): array => [-strlen($c->name), $c->id],
        );

        $this->assertSame(
            ['Cherry', 'Banana', 'Apple'],
            Category::query()->where('parent_id', 1)->orderBy('lft')->pluck('name')->all(),
        );
    }

    public function test_empty_parent_is_silent_no_op(): void
    {
        $leaf = Category::query()->findOrFail(3); // Apple

        $sniffer = new class
        {
            public int $updates = 0;
        };
        DB::listen(static function ($event) use ($sniffer): void {
            if (str_starts_with(ltrim(strtoupper((string) $event->sql)), 'UPDATE')) {
                $sniffer->updates++;
            }
        });

        $leaf->reorderChildrenBy('name');

        $this->assertSame(0, $sniffer->updates);
    }

    public function test_unsaved_parent_throws(): void
    {
        $this->expectException(UnplacedNodeException::class);

        (new Category)->reorderChildrenBy('name');
    }
}
