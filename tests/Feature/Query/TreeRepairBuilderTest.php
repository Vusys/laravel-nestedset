<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Query;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Columns;
use Vusys\NestedSet\Query\TreeRepairBuilder;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Tests for TreeRepairBuilder.
 *
 * Uses the categories table with intentionally broken lft/rgt/depth values
 * to verify that repair methods restore a valid nested-set structure.
 */
final class TreeRepairBuilderTest extends TestCase
{
    private TreeRepairBuilder $repair;

    /** This suite intentionally inserts broken trees to exercise repair. */
    protected bool $allowBrokenTreeAtTearDown = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repair = new TreeRepairBuilder(
            connection: DB::connection(),
            table: 'categories',
            lft: Columns::LFT,
            rgt: Columns::RGT,
            parentId: Columns::PARENT_ID,
            depth: Columns::DEPTH,
        );
    }

    private function seedValid(): void
    {
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root',    'lft' => 1,  'rgt' => 10, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'Child A', 'lft' => 2,  'rgt' => 7,  'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'AA',      'lft' => 3,  'rgt' => 4,  'depth' => 2, 'parent_id' => 2],
            ['id' => 4, 'name' => 'AB',      'lft' => 5,  'rgt' => 6,  'depth' => 2, 'parent_id' => 2],
            ['id' => 5, 'name' => 'Child B', 'lft' => 8,  'rgt' => 9,  'depth' => 1, 'parent_id' => 1],
        ]);
    }

    private function seedBroken(): void
    {
        // All lft/rgt/depth are zeroed — only parent_id is correct.
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root',    'lft' => 0, 'rgt' => 0, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'Child A', 'lft' => 0, 'rgt' => 0, 'depth' => 0, 'parent_id' => 1],
            ['id' => 3, 'name' => 'AA',      'lft' => 0, 'rgt' => 0, 'depth' => 0, 'parent_id' => 2],
            ['id' => 4, 'name' => 'AB',      'lft' => 0, 'rgt' => 0, 'depth' => 0, 'parent_id' => 2],
            ['id' => 5, 'name' => 'Child B', 'lft' => 0, 'rgt' => 0, 'depth' => 0, 'parent_id' => 1],
        ]);
    }

    // ----------------------------------------------------------------
    // countErrors / isBroken / getTotalErrors
    // ----------------------------------------------------------------

    #[Test]
    public function count_errors_returns_zeros_for_valid_tree(): void
    {
        $this->seedValid();

        $errors = $this->repair->countErrors();

        $this->assertSame(0, $errors['invalid_bounds']);
        $this->assertSame(0, $errors['duplicate_lft']);
        $this->assertSame(0, $errors['duplicate_rgt']);
        $this->assertSame(0, $errors['orphans']);
    }

    #[Test]
    public function is_broken_returns_false_for_valid_tree(): void
    {
        $this->seedValid();

        $this->assertFalse($this->repair->isBroken());
    }

    #[Test]
    public function is_broken_returns_true_for_broken_tree(): void
    {
        $this->seedBroken();

        $this->assertTrue($this->repair->isBroken());
    }

    #[Test]
    public function get_total_errors_sums_all_error_types(): void
    {
        $this->seedBroken();

        $this->assertGreaterThan(0, $this->repair->getTotalErrors());
    }

    #[Test]
    public function count_errors_detects_invalid_bounds(): void
    {
        // lft >= rgt for every node.
        $this->seedBroken();

        $errors = $this->repair->countErrors();

        $this->assertGreaterThan(0, $errors['invalid_bounds']);
    }

    #[Test]
    public function count_errors_detects_orphans(): void
    {
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root',    'lft' => 1, 'rgt' => 4, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'Child',   'lft' => 2, 'rgt' => 3, 'depth' => 1, 'parent_id' => 999],
        ]);

        $errors = $this->repair->countErrors();

        $this->assertSame(1, $errors['orphans']);
    }

    // ----------------------------------------------------------------
    // rebuildTree
    // ----------------------------------------------------------------

    #[Test]
    public function rebuild_tree_restores_valid_structure(): void
    {
        $this->seedBroken();
        $this->repair->rebuildTree();

        $this->assertFalse($this->repair->isBroken());
    }

    #[Test]
    public function rebuild_tree_assigns_correct_lft_rgt_to_root(): void
    {
        $this->seedBroken();
        $this->repair->rebuildTree();

        $root = $this->rowById('categories', 1);

        $this->assertSame(1, (int) $root->lft);
        $this->assertSame(10, (int) $root->rgt);
        $this->assertSame(0, (int) $root->depth);
    }

    #[Test]
    public function rebuild_tree_assigns_correct_depth(): void
    {
        $this->seedBroken();
        $this->repair->rebuildTree();

        $aa = $this->rowById('categories', 3);

        $this->assertSame(2, (int) $aa->depth);
    }

    #[Test]
    public function rebuild_tree_produces_contiguous_lft_values(): void
    {
        $this->seedBroken();
        $this->repair->rebuildTree();

        $lfts = Category::query()
            ->orderBy('lft')
            ->get()
            ->map(static fn (Category $c): int => $c->lft)
            ->all();

        $this->assertSame([1, 2, 3, 5, 8], $lfts);
    }

    // ----------------------------------------------------------------
    // fixTree
    // ----------------------------------------------------------------

    #[Test]
    public function fix_tree_returns_tree_fix_result(): void
    {
        $this->seedBroken();

        $result = $this->repair->fixTree();

        $this->assertSame(5, $result->nodesUpdated);
        $this->assertFalse($result->hasErrors());
    }

    #[Test]
    public function fix_tree_leaves_valid_tree_intact(): void
    {
        $this->seedValid();

        $result = $this->repair->fixTree();

        $this->assertFalse($this->repair->isBroken());
        $this->assertSame(5, $result->nodesUpdated);
    }

    // ----------------------------------------------------------------
    // rebuildSubtree
    // ----------------------------------------------------------------

    #[Test]
    public function rebuild_subtree_restores_child_a_bounds(): void
    {
        // Child A's lft and depth are correct; only the rgt and descendants are broken.
        // rebuildSubtree reads the root's lft/depth as the starting anchor.
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root',    'lft' => 1, 'rgt' => 10, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'Child A', 'lft' => 2, 'rgt' => 2,  'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'AA',      'lft' => 0, 'rgt' => 0,  'depth' => 0, 'parent_id' => 2],
            ['id' => 4, 'name' => 'AB',      'lft' => 0, 'rgt' => 0,  'depth' => 0, 'parent_id' => 2],
            ['id' => 5, 'name' => 'Child B', 'lft' => 8, 'rgt' => 9,  'depth' => 1, 'parent_id' => 1],
        ]);

        $this->repair->rebuildSubtree(rootId: 2);

        $childA = $this->rowById('categories', 2);
        $aa = $this->rowById('categories', 3);
        $ab = $this->rowById('categories', 4);

        $this->assertSame(2, (int) $childA->lft);
        $this->assertSame(7, (int) $childA->rgt);
        $this->assertSame(1, (int) $childA->depth);
        $this->assertSame(3, (int) $aa->lft);
        $this->assertSame(4, (int) $aa->rgt);
        $this->assertSame(2, (int) $aa->depth);
        $this->assertSame(5, (int) $ab->lft);
        $this->assertSame(6, (int) $ab->rgt);
        $this->assertSame(2, (int) $ab->depth);
    }

    #[Test]
    public function rebuild_subtree_shifts_surroundings_when_subtree_grows(): void
    {
        // Child A's reserved band (lft=2,rgt=3) is sized for zero descendants,
        // but parent_id says it has two — so the rebuilt subtree needs four
        // extra positions. Child B sits immediately after at lft=4. Without
        // a gap shift, the rebuilt descendants would collide with Child B.
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root',    'lft' => 1, 'rgt' => 8, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'Child A', 'lft' => 2, 'rgt' => 3, 'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'AA',      'lft' => 0, 'rgt' => 0, 'depth' => 0, 'parent_id' => 2],
            ['id' => 4, 'name' => 'AB',      'lft' => 0, 'rgt' => 0, 'depth' => 0, 'parent_id' => 2],
            ['id' => 5, 'name' => 'Child B', 'lft' => 4, 'rgt' => 5, 'depth' => 1, 'parent_id' => 1],
            ['id' => 6, 'name' => 'Child C', 'lft' => 6, 'rgt' => 7, 'depth' => 1, 'parent_id' => 1],
        ]);

        $this->repair->rebuildSubtree(rootId: 2);

        $this->assertBounds([
            1 => [1, 12],
            2 => [2, 7],
            3 => [3, 4],
            4 => [5, 6],
            5 => [8, 9],
            6 => [10, 11],
        ]);
        $this->assertFalse($this->repair->isBroken());
    }

    #[Test]
    public function rebuild_subtree_shifts_surroundings_when_subtree_shrinks(): void
    {
        // Child A's reserved band (lft=2,rgt=9) is sized for three descendants,
        // but parent_id says it only has one — so four positions need to
        // close. Without the shrink shift, Child B's lft=10 would leave a
        // dead gap between Child A's new rgt=5 and Child B's position.
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root',    'lft' => 1, 'rgt' => 12, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'Child A', 'lft' => 2, 'rgt' => 9,  'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'AA',      'lft' => 3, 'rgt' => 4,  'depth' => 2, 'parent_id' => 2],
            ['id' => 5, 'name' => 'Child B', 'lft' => 10, 'rgt' => 11, 'depth' => 1, 'parent_id' => 1],
        ]);

        $this->repair->rebuildSubtree(rootId: 2);

        $this->assertBounds([
            1 => [1, 8],
            2 => [2, 5],
            3 => [3, 4],
            5 => [6, 7],
        ]);
        $this->assertFalse($this->repair->isBroken());
    }

    #[Test]
    public function rebuild_subtree_is_noop_for_already_correctly_sized_band(): void
    {
        // Reserved band matches the subtree size exactly — delta is zero
        // and no surrounding rows should move.
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root',    'lft' => 1, 'rgt' => 8, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'Child A', 'lft' => 2, 'rgt' => 5, 'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'AA',      'lft' => 3, 'rgt' => 4, 'depth' => 2, 'parent_id' => 2],
            ['id' => 5, 'name' => 'Child B', 'lft' => 6, 'rgt' => 7, 'depth' => 1, 'parent_id' => 1],
        ]);

        $this->repair->rebuildSubtree(rootId: 2);

        $this->assertBounds([
            1 => [1, 8],
            2 => [2, 5],
            3 => [3, 4],
            5 => [6, 7],
        ]);
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $expected
     */
    private function assertBounds(array $expected): void
    {
        /** @var array<int, array{0: int, 1: int}> $actual */
        $actual = [];
        foreach (DB::table('categories')->orderBy('id')->get() as $row) {
            /** @var \stdClass $row */
            $actual[(int) $row->id] = [(int) $row->lft, (int) $row->rgt];
        }
        $this->assertSame($expected, $actual);
    }
}
