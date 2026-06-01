<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Listeners;

use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\StatsMonster;
use Vusys\NestedSet\Tests\TestCase;

/**
 * The `filter:` / `filterNotNull:` parameters on
 * #[NestedSetAggregateListener] let users restrict which nodes
 * contribute without rewriting contribution() to return null.
 * Filter watch columns join the listener's own watchColumns(), so a
 * mutation that flips filter membership re-triggers ancestor
 * maintenance.
 */
final class ListenerFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    private function asFloat(mixed $value): float
    {
        if ($value === null || ! is_numeric($value)) {
            $this->fail('Expected numeric, got '.get_debug_type($value));
        }

        return (float) $value;
    }

    /**
     * fire_score_sum sums ScoreListener contributions only for rows
     * where type = 'fire'. Non-fire nodes are excluded entirely from
     * the ancestor roll-up.
     */
    public function test_equality_filter_excludes_non_matching_rows(): void
    {
        $root = new StatsMonster(['name' => 'r', 'type' => 'fire', 'score' => 10.0]);
        $root->saveAsRoot();

        (new StatsMonster(['name' => 'c1', 'type' => 'fire', 'score' => 5.0]))
            ->appendToNode($root)
            ->save();
        (new StatsMonster(['name' => 'c2', 'type' => 'water', 'score' => 99.0]))
            ->appendToNode($root)
            ->save();

        $root->refresh();

        // Only the two fire rows contribute: 10 + 5 = 15. Water row excluded.
        $this->assertSame(15.0, $this->asFloat($root->fire_score_sum));
    }

    /**
     * Flipping `type` from non-fire to fire on a descendant must
     * propagate the now-matching row into ancestor totals on the
     * very next save — proves the filter watch columns reach the
     * dirty-detection path that drives ancestor maintenance.
     */
    public function test_filter_watch_columns_trigger_recompute_when_membership_changes(): void
    {
        $root = new StatsMonster(['name' => 'r', 'type' => 'fire', 'score' => 10.0]);
        $root->saveAsRoot();

        $child = new StatsMonster(['name' => 'c', 'type' => 'water', 'score' => 7.0]);
        $child->appendToNode($root)->save();

        $root->refresh();
        $this->assertSame(10.0, $this->asFloat($root->fire_score_sum));

        $child->type = 'fire';
        $child->save();

        $root->refresh();
        $this->assertSame(17.0, $this->asFloat($root->fire_score_sum));
    }

    /**
     * non_null_score_avg averages contributions only over rows where
     * `score` is non-null. Auto-promoted Sum + Count companions
     * inherit the filter, so the AVG arithmetic uses the right N.
     */
    public function test_filter_not_null_excludes_null_source_rows_from_avg(): void
    {
        $root = new StatsMonster(['name' => 'r', 'type' => 'fire', 'score' => 10.0]);
        $root->saveAsRoot();

        (new StatsMonster(['name' => 'c1', 'type' => 'fire', 'score' => 20.0]))
            ->appendToNode($root)
            ->save();
        // Null score: contribution() also returns null, but the filter
        // arm fires earlier and produces the same exclusion.
        (new StatsMonster(['name' => 'c2', 'type' => 'fire', 'score' => null]))
            ->appendToNode($root)
            ->save();

        $root->refresh();

        // Root + first child contribute (10, 20); second child excluded.
        $this->assertSame(15.0, $this->asFloat($root->non_null_score_avg));
        $this->assertSame(30.0, $this->asFloat($root->non_null_score_avg__sum));
        $this->assertSame(2, (int) $root->non_null_score_avg__count);
    }

    /**
     * Auto-promoted companions for a filtered listener AVG must inherit
     * the parent's filter. Without inheritance the companion sums every
     * row and the AVG silently drifts.
     */
    public function test_companions_inherit_filter_from_parent(): void
    {
        $defs = AggregateRegistry::for(StatsMonster::class);

        $sumColumn = 'non_null_score_avg__sum';
        $foundSum = null;
        foreach ($defs as $def) {
            if ($def->getColumn() === $sumColumn) {
                $foundSum = $def;
                break;
            }
        }

        $this->assertNotNull($foundSum, "companion $sumColumn should be auto-promoted");
        $this->assertNotNull($foundSum->filter ?? null, 'companion should inherit a filter');
        $this->assertSame(['score'], $foundSum->filter->watchColumns());
    }

    /**
     * fixAggregates() over a drifted tree restores filtered listener
     * sums to their correct value — proves the DFS-fix path also
     * applies the filter (not just the per-mutation path).
     */
    public function test_fix_aggregates_respects_filter(): void
    {
        $root = new StatsMonster(['name' => 'r', 'type' => 'fire', 'score' => 1.0]);
        $root->saveAsRoot();

        (new StatsMonster(['name' => 'c1', 'type' => 'fire', 'score' => 2.0]))
            ->appendToNode($root)
            ->save();
        (new StatsMonster(['name' => 'c2', 'type' => 'water', 'score' => 999.0]))
            ->appendToNode($root)
            ->save();

        // Forcibly corrupt the stored sum and prove fix restores it.
        StatsMonster::query()->where('id', $root->id)->update(['fire_score_sum' => 12345]);

        StatsMonster::fixAggregates();

        $root->refresh();
        $this->assertSame(3.0, $this->asFloat($root->fire_score_sum));
    }
}
