<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Diff;

use Vusys\NestedSet\Diff\TreeDiff;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Diff identity keyed by `name` (not `id`); the default resolver does
 * one `whereIn('name', […])` to translate back to primary keys.
 */
final class TreeDiffApplyResolverTest extends TestCase
{
    public function test_default_resolver_translates_name_to_primary_key(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->makeRoot()->save();
        $child = new Category(['name' => 'Child']);
        $child->appendToNode($root)->save();

        $before = [
            ['name' => 'Root', 'parent_id' => null],
            ['name' => 'Child', 'parent_id' => null],
        ];
        $after = [
            ['name' => 'Root', 'title' => 'I have a title now', 'parent_id' => null],
            ['name' => 'Child', 'parent_id' => null],
        ];

        $diff = TreeDiff::between($before, $after, on: 'name');
        $this->assertSame(1, $diff->summary()['modified']);

        $diff->apply(Category::class);

        $this->assertSame('I have a title now', $root->refresh()->title);
    }

    public function test_custom_resolver_closure_is_used_when_supplied(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->makeRoot()->save();

        $before = [['slug' => 'root', 'parent_id' => null, 'name' => 'Root']];
        $after = [['slug' => 'root', 'parent_id' => null, 'name' => 'Renamed']];

        $diff = TreeDiff::between($before, $after, on: 'slug');

        $called = 0;
        $resolver = function (mixed $identity) use ($root, &$called): int {
            $called++;
            $this->assertSame('root', $identity);

            return $root->id;
        };

        $diff->apply(Category::class, resolver: $resolver);

        $this->assertSame('Renamed', $root->refresh()->name);
        $this->assertSame(1, $called);
    }
}
