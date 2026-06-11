<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Listeners;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\StatsMonster;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Listener MIN/MAX update path used `Numeric::contributionOrZero()` to
 * collapse a null contribution to 0, then compared that 0 against the
 * old value — pushing a fabricated 0 candidate to every ancestor when a
 * contribution went from a value to null (and missing a genuinely new
 * candidate on the null→value transition). A null contribution means
 * "this row does not participate", same as a NULL SQL source, so it must
 * route to a recompute rather than an extreme-candidate delta.
 */
final class ListenerMinMaxNullContributionTest extends TestCase
{
    use InteractsWithTrees;

    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    #[Test]
    public function min_listener_recomputes_when_holder_contribution_becomes_null(): void
    {
        $root = new StatsMonster(['name' => 'Root', 'score' => 10.0]);
        $root->saveAsRoot();

        $child = new StatsMonster(['name' => 'Child', 'score' => 5.0]);
        $child->appendToNode($root->refresh())->save();

        $root->refresh();
        $this->assertSame(5.0, (float) $root->score_min);

        // 5 → null: ScoreListener returns null for a null score, so the
        // child drops out of the MIN. Pre-fix this pushed a 0 candidate
        // (0 < 5) and root's MIN collapsed to 0; fresh is 10.
        $child->score = null;
        $child->save();

        $root->refresh();
        $this->assertSame(10.0, (float) $root->score_min);
        $this->assertAggregateMatchesFresh($root, 'score_min');
    }

    #[Test]
    public function min_listener_extends_when_contribution_appears(): void
    {
        $root = new StatsMonster(['name' => 'Root', 'score' => 10.0]);
        $root->saveAsRoot();

        $child = new StatsMonster(['name' => 'Child', 'score' => null]);
        $child->appendToNode($root->refresh())->save();

        $root->refresh();
        $this->assertSame(10.0, (float) $root->score_min);

        // null → 3: a new MIN candidate below the current minimum.
        $child->score = 3.0;
        $child->save();

        $root->refresh();
        $this->assertSame(3.0, (float) $root->score_min);
        $this->assertAggregateMatchesFresh($root, 'score_min');
    }
}
