<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Listeners;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\Monster;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Coverage for the **exclusive** branch of the listener-aggregate
 * subtree-bounds check. Two parallel sites compute "is `inner` inside
 * the outer's subtree?" — inclusive (`>=`/`<=`) for the default case
 * and exclusive (`>`/`<`) when the outer's own contribution is meant
 * to be skipped:
 *
 *   src/Aggregates/Listeners/ListenerMaintenance.php:123  (fix path)
 *   src/Aggregates/Listeners/ListenerMaintenance.php:232  (errors path)
 *
 * Until Monster declared the exclusive `descendant_fire_count` column,
 * no listener fixture took the exclusive branch — the exclusive arm of
 * the bounds check never executed at all. The boundary case is when
 * `inner.lft === outer.lft` (the outer itself): inclusive must count
 * it, exclusive must skip it. The nested-set invariant (unique lft per
 * scope) means `>` and `>=` are *behaviourally* equivalent for any
 * well-formed tree — but that's an invariant of the data, not the
 * check, and the exclusive arm should be on its own footing in case a
 * future code path runs the fold over rows that haven't yet had their
 * bounds re-stamped. These tests assert the end-to-end value, which
 * is what users actually depend on.
 *
 * Tree:
 *
 *   Root(fire)
 *   ├── A(fire)
 *   │   └── A1(fire)
 *   └── B(water)
 *
 * Inclusive fire_count (existing column):
 *   Root = 3 (itself + A + A1)
 *   A    = 2 (itself + A1)
 *   A1   = 1
 *   B    = 0
 *
 * Exclusive descendant_fire_count (new column):
 *   Root = 2 (A + A1 — Root itself is *not* counted, even though it's fire)
 *   A    = 1 (A1 — A itself is not counted)
 *   A1   = 0 (leaf — nothing below)
 *   B    = 0
 */
final class ExclusiveListenerBoundsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    /** @return array{root: Monster, a: Monster, a1: Monster, b: Monster} */
    private function buildTree(): array
    {
        $root = new Monster(['name' => 'Root', 'type' => 'fire', 'base_power' => 1, 'level' => 1]);
        $root->saveAsRoot();

        $a = new Monster(['name' => 'A', 'type' => 'fire', 'base_power' => 1, 'level' => 1]);
        $a->appendToNode($root)->save();

        $a1 = new Monster(['name' => 'A1', 'type' => 'fire', 'base_power' => 1, 'level' => 1]);
        $a1->appendToNode($a->refresh())->save();

        $b = new Monster(['name' => 'B', 'type' => 'water', 'base_power' => 1, 'level' => 1]);
        $b->appendToNode($root->refresh())->save();

        return ['root' => $root->refresh(), 'a' => $a->refresh(), 'a1' => $a1->refresh(), 'b' => $b->refresh()];
    }

    /** Self isn't counted: Root's own fire contribution is excluded from its descendant count. */
    #[Test]
    public function exclusive_listener_excludes_self_at_root(): void
    {
        $t = $this->buildTree();

        // Inclusive baseline still counts every fire including Root.
        $this->assertSame(3, (int) $t['root']->fire_count);
        // Exclusive must NOT count Root itself even though Root is fire.
        $this->assertSame(2, (int) $t['root']->descendant_fire_count);
    }

    /** Mid-tree node: A is fire but must not contribute to its own descendant_fire_count. */
    #[Test]
    public function exclusive_listener_excludes_self_mid_tree(): void
    {
        $t = $this->buildTree();

        $this->assertSame(2, (int) $t['a']->fire_count);             // self + A1
        $this->assertSame(1, (int) $t['a']->descendant_fire_count);  // A1 only
    }

    /** Leaf node: exclusive is always zero — leaves have no strict descendants. */
    #[Test]
    public function exclusive_listener_at_leaf_is_zero(): void
    {
        $t = $this->buildTree();

        $this->assertSame(1, (int) $t['a1']->fire_count);             // self
        $this->assertSame(0, (int) $t['a1']->descendant_fire_count);  // strict descendants
        $this->assertSame(0, (int) $t['b']->fire_count);              // non-fire leaf
        $this->assertSame(0, (int) $t['b']->descendant_fire_count);
    }

    /**
     * `fixAggregates()` reaches ListenerMaintenance.php:123 — drift one
     * exclusive column, rebuild, and assert it's restored to the strict-
     * bounds value. Verifies the exclusive arm of the recompute path
     * runs and produces the descendants-only rollup.
     */
    #[Test]
    public function fix_aggregates_recomputes_exclusive_listener_with_strict_bounds(): void
    {
        $t = $this->buildTree();

        // Drift Root's exclusive column to a wrong-but-plausible value.
        DB::table('monsters')->where('id', $t['root']->id)->update(['descendant_fire_count' => 99]);

        Monster::fixAggregates();

        $this->assertSame(2, (int) $t['root']->refresh()->descendant_fire_count);
        // Leaves still zero, mid-tree A still 1.
        $this->assertSame(1, (int) $t['a']->refresh()->descendant_fire_count);
        $this->assertSame(0, (int) $t['a1']->refresh()->descendant_fire_count);
    }

    /**
     * `aggregateErrors()` reaches ListenerMaintenance.php:232 — the
     * exclusive path's drift detector. Verifies the comparator's
     * exclusive fold agrees with the stored values for a tree with
     * mixed-contribution rows.
     */
    #[Test]
    public function aggregate_errors_validates_exclusive_listener_with_strict_bounds(): void
    {
        $this->buildTree();

        $errors = Monster::aggregateErrors();

        $this->assertSame(0, $errors['descendant_fire_count'] ?? -1);
    }
}
