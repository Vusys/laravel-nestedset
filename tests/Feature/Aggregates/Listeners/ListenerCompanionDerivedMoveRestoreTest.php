<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Listeners;

use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\StatsMonster;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Move + restore lifecycle for the listener companion-derived display
 * ops (Variance / Stddev / GeometricMean / HarmonicMean).
 *
 * Before the fix, collectMoveSubtreeContribution() and
 * applyAggregateOnRestore() (non-soft-delete branch) silently routed
 * these ops into the Min/Max extremes bucket — DeltaMaintenance then
 * wrote a corrupted display value via buildExtremeSetClauses(). The
 * before/after-move hooks also omitted them from the listener chain
 * recompute selection, so the old/new ancestor chains never got their
 * Variance/Stddev/GeoMean/HarmonicMean re-derived on a move.
 *
 * The assertions here all read the stored display column after a move
 * or restore and compare to the brute-force value computed by the
 * same formulas off the live tree state. If maintenance silently
 * corrupts the column, the deltas are large (orders of magnitude
 * larger than `1e-9`), so even a tight tolerance catches the bug.
 */
final class ListenerCompanionDerivedMoveRestoreTest extends TestCase
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
     * Builds a two-branch tree:
     *
     *   root (score=10)
     *     ├── leftRoot (score=2)
     *     │     ├── (score=4)
     *     │     └── (score=8)
     *     └── rightRoot (score=100)
     *           ├── (score=1)
     *           └── (score=9)
     *
     * Moving leftRoot under rightRoot reshapes both sides, exercising
     * the before-move (old chain) and after-move (new chain) hooks.
     *
     * @return array{root: StatsMonster, leftRoot: StatsMonster, rightRoot: StatsMonster}
     */
    private function seedTree(): array
    {
        $root = new StatsMonster(['name' => 'root', 'type' => 'fire', 'score' => 10.0]);
        $root->saveAsRoot();

        $leftRoot = new StatsMonster(['name' => 'L', 'type' => 'fire', 'score' => 2.0]);
        $leftRoot->appendToNode($root)->save();
        (new StatsMonster(['name' => 'L1', 'type' => 'fire', 'score' => 4.0]))->appendToNode($leftRoot)->save();
        (new StatsMonster(['name' => 'L2', 'type' => 'fire', 'score' => 8.0]))->appendToNode($leftRoot)->save();

        $rightRoot = new StatsMonster(['name' => 'R', 'type' => 'fire', 'score' => 100.0]);
        $rightRoot->appendToNode($root)->save();
        (new StatsMonster(['name' => 'R1', 'type' => 'fire', 'score' => 1.0]))->appendToNode($rightRoot)->save();
        (new StatsMonster(['name' => 'R2', 'type' => 'fire', 'score' => 9.0]))->appendToNode($rightRoot)->save();

        return ['root' => $root->refresh(), 'leftRoot' => $leftRoot->refresh(), 'rightRoot' => $rightRoot->refresh()];
    }

    /**
     * Brute-force variance / stddev / geomean / harmean over a node's
     * descendant scores (inclusive). Reads the live tree, so it
     * naturally reflects post-mutation state.
     *
     * @return array{variance: ?float, stddev: ?float, geomean: ?float, harmean: ?float, min: ?float, count: int}
     */
    private function expected(StatsMonster $node): array
    {
        /** @var list<float> $scores */
        $scores = [];
        $self = StatsMonster::query()->find($node->id);
        $this->assertNotNull($self);
        if ($self->score !== null) {
            $scores[] = (float) $self->score;
        }
        $descendants = StatsMonster::query()
            ->where('lft', '>', $self->lft)
            ->where('rgt', '<', $self->rgt)
            ->get();
        foreach ($descendants as $d) {
            if ($d->score !== null) {
                $scores[] = (float) $d->score;
            }
        }

        $count = count($scores);
        if ($count === 0) {
            return ['variance' => null, 'stddev' => null, 'geomean' => null, 'harmean' => null, 'min' => null, 'count' => 0];
        }

        $sum = array_sum($scores);
        $sumSq = array_sum(array_map(static fn (float $x): float => $x * $x, $scores));
        $variance = max(0.0, ($count * $sumSq - $sum * $sum) / ($count * $count));

        $positive = array_filter($scores, static fn (float $x): bool => $x > 0);
        $countPos = count($positive);
        $geomean = $countPos === 0
            ? null
            : exp(array_sum(array_map(log(...), $positive)) / $countPos);

        $nonZero = array_filter($scores, static fn (float $x): bool => $x !== 0.0);
        $countNonZero = count($nonZero);
        $sumRecip = array_sum(array_map(static fn (float $x): float => 1.0 / $x, $nonZero));
        $harmean = ($countNonZero === 0 || $sumRecip === 0.0)
            ? null
            : $countNonZero / $sumRecip;

        return [
            'variance' => $variance,
            'stddev' => sqrt($variance),
            'geomean' => $geomean,
            'harmean' => $harmean,
            'min' => min($scores),
            'count' => $count,
        ];
    }

    private function assertAggregatesMatch(StatsMonster $node, string $label): void
    {
        $node->refresh();
        $expected = $this->expected($node);

        $this->assertEqualsWithDelta(
            $expected['variance'],
            $node->score_variance === null ? null : $this->asFloat($node->score_variance),
            1e-9,
            "{$label}: variance",
        );
        $this->assertEqualsWithDelta(
            $expected['stddev'],
            $node->score_stddev === null ? null : $this->asFloat($node->score_stddev),
            1e-9,
            "{$label}: stddev",
        );
        $this->assertEqualsWithDelta(
            $expected['geomean'],
            $node->score_geomean === null ? null : $this->asFloat($node->score_geomean),
            1e-9,
            "{$label}: geomean",
        );
        $this->assertEqualsWithDelta(
            $expected['harmean'],
            $node->score_harmean === null ? null : $this->asFloat($node->score_harmean),
            1e-9,
            "{$label}: harmean",
        );
        // score_min is the inclusive Min listener — pins the Min arm of
        // the move/restore chain-recompute selection alongside the
        // companion-derived ops.
        $this->assertEqualsWithDelta(
            $expected['min'],
            $node->score_min === null ? null : $this->asFloat($node->score_min),
            1e-9,
            "{$label}: min",
        );
    }

    public function test_move_subtree_recomputes_companion_derived_ops_on_both_chains(): void
    {
        ['root' => $root, 'leftRoot' => $leftRoot, 'rightRoot' => $rightRoot] = $this->seedTree();

        $this->assertAggregatesMatch($root, 'pre-move root');
        $this->assertAggregatesMatch($leftRoot, 'pre-move leftRoot');
        $this->assertAggregatesMatch($rightRoot, 'pre-move rightRoot');

        $leftRoot->appendToNode($rightRoot)->save();

        $this->assertAggregatesMatch($root, 'post-move root');
        $this->assertAggregatesMatch($rightRoot, 'post-move rightRoot');
        $this->assertAggregatesMatch($leftRoot, 'post-move leftRoot (now under rightRoot)');
    }

    public function test_move_subtree_out_of_old_chain_recomputes_companion_derived_ops_on_the_old_chain(): void
    {
        ['root' => $root, 'leftRoot' => $leftRoot] = $this->seedTree();

        // A second, independent root the moving subtree lands under, so
        // the old chain genuinely *loses* nodes.
        $other = new StatsMonster(['name' => 'other', 'type' => 'fire', 'score' => 50.0]);
        $other->saveAsRoot();

        $this->assertAggregatesMatch($root, 'pre-move root');

        // The both-chains test above moves leftRoot *under* rightRoot,
        // which is itself under root — so root keeps every node and its
        // companion-derived columns never change, hiding whether the
        // before-move old-chain recompute selection actually fired.
        // Moving leftRoot onto a separate root removes leftRoot/L1/L2
        // from root entirely, so root's variance/stddev/geomean/harmean
        // must be re-derived by the old-chain hook — were any of those
        // ops dropped from the recompute selection, root's columns would
        // go stale against the brute-force expectation.
        $leftRoot->refresh()->appendToNode($other->refresh())->save();

        $this->assertAggregatesMatch($root, 'post-move root (old chain shrank)');
        $this->assertAggregatesMatch($other, 'post-move other (new chain grew)');
        $this->assertAggregatesMatch($leftRoot, 'post-move leftRoot (now under other)');
    }

    public function test_hard_delete_recomputes_companion_derived_ops_on_chain(): void
    {
        ['root' => $root, 'leftRoot' => $leftRoot] = $this->seedTree();

        $leftRoot->forceDelete();

        $this->assertAggregatesMatch($root, 'post-delete root');
    }

    /**
     * Restore must skip listener Min/Max columns whose stored value is
     * NULL (filtered Min with no in-filter contributors, or empty
     * subtree). Before the fix, Numeric::asNumericOrZero coerced NULL
     * → 0 and wrote that into $extremes; DeltaMaintenance then
     * compared `0 < col` for every ancestor and clobbered any legit
     * positive Min down to 0. This mirrors the guard already present
     * in collectMoveSubtreeContribution().
     */
    public function test_non_soft_delete_restore_skips_null_listener_min(): void
    {
        // Ancestor with a legit Min of 5.
        $root = new StatsMonster(['name' => 'root', 'type' => 'fire', 'score' => 5.0]);
        $root->saveAsRoot();

        // Restored subtree root has all-null scores, so ScoreListener
        // returns null for every node and score_min stores NULL.
        $branch = new StatsMonster(['name' => 'branch', 'type' => 'fire', 'score' => null]);
        $branch->appendToNode($root)->save();
        (new StatsMonster(['name' => 'leaf', 'type' => 'fire', 'score' => null]))->appendToNode($branch)->save();

        $branch->refresh();
        $this->assertNull($branch->score_min, 'precondition: branch.score_min must be NULL');
        $root->refresh();
        $this->assertSame(5.0, $this->asFloat($root->score_min), 'precondition: root.score_min stays 5');

        $branch->applyAggregateOnRestore();

        $root->refresh();
        $this->assertSame(
            5.0,
            $this->asFloat($root->score_min),
            'restore must not clobber ancestor.score_min to 0 when restored subtree stores NULL',
        );
    }

    /**
     * Non-soft-delete branch of applyAggregateOnRestore() — StatsMonster
     * does NOT use SoftDeletes, so calling the hook directly is the only
     * way to reach the branch. Mirrors the StructuralMutationMaintenanceTest
     * pattern for Area's non-soft-delete restore coverage. Before the
     * fix, Variance/Stddev/GeoMean/HarmonicMean fell into $extremes and
     * got rewritten by DeltaMaintenance::buildExtremeSetClauses() —
     * silently corrupting the display column on every restore.
     */
    public function test_non_soft_delete_restore_recomputes_companion_derived_ops(): void
    {
        ['root' => $root, 'leftRoot' => $leftRoot] = $this->seedTree();

        // Knock ancestor values out so we can see the restore path
        // re-derive them. This simulates the state right after a
        // hypothetical detach where the ancestor chain has lost the
        // subtree's contribution.
        StatsMonster::query()->where('id', $root->id)->update([
            'score_variance' => 0,
            'score_stddev' => 0,
            'score_geomean' => 0,
            'score_harmean' => 0,
        ]);

        $leftRoot->refresh()->applyAggregateOnRestore();

        $this->assertAggregatesMatch($root, 'after direct applyAggregateOnRestore call');
    }
}
