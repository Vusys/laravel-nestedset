<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Query;

use Closure;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeBounds;
use Vusys\NestedSet\Query\TreeQueryBuilder;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * The tree predicates take the node itself as well as its NodeBounds (#264),
 * and both give the same rows.
 */
final class NodeOrBoundsArgumentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Root(1,10) > A(2,7) > AA(3,4), AB(5,6); Root > B(8,9)
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root', 'lft' => 1, 'rgt' => 10, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'A', 'lft' => 2, 'rgt' => 7, 'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'AA', 'lft' => 3, 'rgt' => 4, 'depth' => 2, 'parent_id' => 2],
            ['id' => 4, 'name' => 'AB', 'lft' => 5, 'rgt' => 6, 'depth' => 2, 'parent_id' => 2],
            ['id' => 5, 'name' => 'B', 'lft' => 8, 'rgt' => 9, 'depth' => 1, 'parent_id' => 1],
        ]);
        $this->syncSequence('categories');
    }

    /**
     * @return iterable<string, array{Closure(TreeQueryBuilder<Category>, HasNestedSet|NodeBounds): TreeQueryBuilder<Category>, list<string>}>
     */
    public static function predicates(): iterable
    {
        yield 'whereDescendantOf' => [static fn (TreeQueryBuilder $q, HasNestedSet|NodeBounds $a): TreeQueryBuilder => $q->whereDescendantOf($a), ['AA', 'AB']];
        yield 'whereDescendantOrSelf' => [static fn (TreeQueryBuilder $q, HasNestedSet|NodeBounds $a): TreeQueryBuilder => $q->whereDescendantOrSelf($a), ['A', 'AA', 'AB']];
        yield 'descendantsOf' => [static fn (TreeQueryBuilder $q, HasNestedSet|NodeBounds $a): TreeQueryBuilder => $q->descendantsOf($a), ['AA', 'AB']];
        yield 'whereAncestorOf' => [static fn (TreeQueryBuilder $q, HasNestedSet|NodeBounds $a): TreeQueryBuilder => $q->whereAncestorOf($a), ['Root']];
        yield 'whereAncestorOrSelf' => [static fn (TreeQueryBuilder $q, HasNestedSet|NodeBounds $a): TreeQueryBuilder => $q->whereAncestorOrSelf($a), ['Root', 'A']];
        yield 'ancestorsOf' => [static fn (TreeQueryBuilder $q, HasNestedSet|NodeBounds $a): TreeQueryBuilder => $q->ancestorsOf($a), ['Root']];
        yield 'whereIsAfter' => [static fn (TreeQueryBuilder $q, HasNestedSet|NodeBounds $a): TreeQueryBuilder => $q->whereIsAfter($a), ['B']];
        yield 'whereIsBefore' => [static fn (TreeQueryBuilder $q, HasNestedSet|NodeBounds $a): TreeQueryBuilder => $q->whereIsBefore($a), []];
    }

    /**
     * @param  Closure(TreeQueryBuilder<Category>, HasNestedSet|NodeBounds): TreeQueryBuilder<Category>  $predicate
     * @param  list<string>  $expected
     */
    #[Test]
    #[DataProvider('predicates')]
    public function the_node_and_its_bounds_select_the_same_rows(Closure $predicate, array $expected): void
    {
        $a = Category::query()->findOrFail(2);

        $byNode = $predicate(Category::query(), $a)->defaultOrder()->pluck('name')->all();
        $byBounds = $predicate(Category::query(), $a->getBounds())->defaultOrder()->pluck('name')->all();

        self::assertSame($expected, $byNode);
        self::assertSame($byBounds, $byNode);
    }
}
