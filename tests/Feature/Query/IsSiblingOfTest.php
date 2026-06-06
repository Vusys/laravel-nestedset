<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Query;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Concerns\HasNodeInspection;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeBounds;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\Fixtures\Models\Menu;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Direct tests for {@see HasNodeInspection::isSiblingOf()}.
 *
 * Two nodes are siblings when they share a parent AND live in the
 * same tree. For scoped models the parent_id check alone is not
 * scope-isolating — every scope has its own NULL-parent roots — so
 * `isSiblingOf` additionally requires same-scope membership, matching
 * the convention `children()` / `prevSibling()` / `nextSibling()`
 * already follow.
 */
final class IsSiblingOfTest extends TestCase
{
    public function test_same_parent_children_are_siblings(): void
    {
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root', 'lft' => 1, 'rgt' => 6, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'A',    'lft' => 2, 'rgt' => 3, 'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'B',    'lft' => 4, 'rgt' => 5, 'depth' => 1, 'parent_id' => 1],
        ]);
        $this->syncSequence('categories');

        $a = Category::query()->findOrFail(2);
        $b = Category::query()->findOrFail(3);

        $this->assertTrue($a->isSiblingOf($b));
        $this->assertTrue($b->isSiblingOf($a), 'sibling relation is symmetric');
    }

    public function test_different_parent_children_are_not_siblings(): void
    {
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'R1', 'lft' => 1,  'rgt' => 4,  'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'C1', 'lft' => 2,  'rgt' => 3,  'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'R2', 'lft' => 5,  'rgt' => 8,  'depth' => 0, 'parent_id' => null],
            ['id' => 4, 'name' => 'C2', 'lft' => 6,  'rgt' => 7,  'depth' => 1, 'parent_id' => 3],
        ]);
        $this->syncSequence('categories');

        $c1 = Category::query()->findOrFail(2);
        $c2 = Category::query()->findOrFail(4);

        $this->assertFalse($c1->isSiblingOf($c2));
        $this->assertFalse($c2->isSiblingOf($c1));
    }

    public function test_self_is_not_its_own_sibling(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $root = $root->refresh();

        $a = new Category(['name' => 'A']);
        $a->appendToNode($root)->save();
        $a = $a->refresh();

        $this->assertFalse($a->isSiblingOf($a));
        $this->assertFalse($root->isSiblingOf($root), 'a root is not its own sibling either');
    }

    public function test_two_unscoped_roots_are_siblings(): void
    {
        // Unscoped (no #[NestedSetScope]) forest: two roots in the same
        // table share parent_id = null and live in the same partition,
        // so reporting them as siblings is intentional.
        $r1 = new Category(['name' => 'R1']);
        $r1->saveAsRoot();

        $r2 = new Category(['name' => 'R2']);
        $r2->saveAsRoot();

        $r1->refresh();
        $r2->refresh();

        $this->assertTrue($r1->isSiblingOf($r2));
    }

    public function test_two_roots_in_different_scopes_are_not_siblings(): void
    {
        // MenuItem is scoped by menu_id. Two roots in different menus
        // both have parent_id = null, but they're in entirely separate
        // trees — the package's convention (children, prev/nextSibling)
        // is scope-isolating, and isSiblingOf follows suit.
        $m1 = Menu::create(['name' => 'M1']);
        $m2 = Menu::create(['name' => 'M2']);

        $a = new MenuItem(['name' => 'A', 'menu_id' => $m1->id]);
        $a->saveAsRoot();

        $b = new MenuItem(['name' => 'B', 'menu_id' => $m2->id]);
        $b->saveAsRoot();

        $a->refresh();
        $b->refresh();

        $this->assertFalse(
            $a->isSiblingOf($b),
            'roots in different scopes share parent_id = null but live in separate trees',
        );
        $this->assertFalse($b->isSiblingOf($a), 'sibling relation stays symmetric across the scope guard');
    }

    public function test_two_children_in_the_same_scope_with_the_same_parent_are_siblings(): void
    {
        // Same-scope companion to the cross-scope negative case above:
        // when both nodes share menu_id AND parent_id, they're siblings.
        $m = Menu::create(['name' => 'M']);

        $root = new MenuItem(['name' => 'R', 'menu_id' => $m->id]);
        $root->saveAsRoot();
        $root->refresh();

        $a = new MenuItem(['name' => 'A', 'menu_id' => $m->id]);
        $a->appendToNode($root)->save();

        $b = new MenuItem(['name' => 'B', 'menu_id' => $m->id]);
        $b->appendToNode($root->refresh())->save();

        $a->refresh();
        $b->refresh();

        $this->assertTrue($a->isSiblingOf($b));
    }

    public function test_roots_are_not_siblings_of_their_own_children(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $root = $root->refresh();

        $child = new Category(['name' => 'Child']);
        $child->appendToNode($root)->save();
        $child->refresh();

        $this->assertFalse($root->isSiblingOf($child));
        $this->assertFalse($child->isSiblingOf($root));
    }

    /**
     * Pins the non-Model fallback in {@see HasNodeInspection::isSiblingOf()}.
     *
     * The scope-equality check guards on `$this instanceof Model &&
     * $other instanceof Model` — both must be Models for
     * `NestedSetScopeResolver::sameScope()` to run (its signature
     * requires `Model&HasNestedSet` on both sides). When `$other` is a
     * `HasNestedSet` stub that isn't a Model (e.g. unit-test
     * doubles), the method must return parent_id equality without
     * attempting to call `sameScope` — otherwise the call would crash
     * with a type error at runtime.
     *
     * Without the test below the `&&` short-circuit could be silently
     * relaxed to a single-side `instanceof` check (a mutation
     * Infection has been seen to escape) without any existing test
     * failing. This case is unreachable from the package's public
     * surface today — every NodeTrait host is a Model — but the
     * interface intentionally widens `$other` to `HasNestedSet`, so
     * the safety net belongs in the test suite.
     */
    public function test_non_model_other_falls_through_to_parent_id_equality(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $root = $root->refresh();

        // Stub: implements HasNestedSet but is NOT an Eloquent Model.
        // Same parent_id as $root (both null).
        $stub = new class implements HasNestedSet
        {
            public function getLft(): int
            {
                return 11;
            }

            public function getRgt(): int
            {
                return 12;
            }

            public function getDepth(): int
            {
                return 0;
            }

            public function getParentId(): ?int
            {
                return null;
            }

            public function getBounds(): NodeBounds
            {
                return new NodeBounds(11, 12, 0);
            }

            public function getLftName(): string
            {
                return 'lft';
            }

            public function getRgtName(): string
            {
                return 'rgt';
            }

            public function getDepthName(): string
            {
                return 'depth';
            }

            public function getParentIdName(): string
            {
                return 'parent_id';
            }

            public function isPlacedInTree(): bool
            {
                return true;
            }
        };

        // Same parent_id (null vs null) AND non-Model fallback path →
        // true. If the && guard were relaxed and sameScope were
        // called with the stub, the Model&HasNestedSet type hint
        // would throw a TypeError — proving the guard is what kept
        // this path safe.
        $this->assertTrue($root->isSiblingOf($stub));

        // Differing parent_id still short-circuits to false before
        // the Model check — independent of the fallback branch.
        $stubWithParent = new class implements HasNestedSet
        {
            public function getLft(): int
            {
                return 11;
            }

            public function getRgt(): int
            {
                return 12;
            }

            public function getDepth(): int
            {
                return 1;
            }

            public function getParentId(): int
            {
                return 999;
            }

            public function getBounds(): NodeBounds
            {
                return new NodeBounds(11, 12, 1);
            }

            public function getLftName(): string
            {
                return 'lft';
            }

            public function getRgtName(): string
            {
                return 'rgt';
            }

            public function getDepthName(): string
            {
                return 'depth';
            }

            public function getParentIdName(): string
            {
                return 'parent_id';
            }

            public function isPlacedInTree(): bool
            {
                return true;
            }
        };

        $this->assertFalse($root->isSiblingOf($stubWithParent));
    }
}
