<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Runabout;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Feature\Runabout\Journeys\CloneOrchardJourney;
use Vusys\NestedSet\Tests\TestCase;
use Vusys\Runabout\RunsJourneys;

/**
 * Runabout journey: subtree clones keep the tree and its aggregate
 * columns coherent under every shuffled ordering of clones, grafts,
 * moves, and source edits. Where the aggregate fuzzers drive the
 * per-row delta / recompute paths, cloning bulk-inserts a whole
 * subtree and back-fills aggregates with one deferred recompute — so a
 * clone that reads stale bounds or leaks a source aggregate drifts.
 * See {@see CloneOrchardJourney}.
 */
#[Group('runabout')]
final class CloneOrchardJourneyTest extends TestCase
{
    use RunsJourneys;

    #[Test]
    public function clones_keep_structure_and_aggregates_coherent(): void
    {
        // repeatHeavy re-clones and re-moves the same nodes, which is how
        // a clone-of-a-clone recompute drifts against a fresh read.
        $this->journey(CloneOrchardJourney::class)
            ->repeatHeavy()
            ->shuffles(25)
            ->run();
    }
}
