<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Runabout;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Feature\Runabout\Journeys\JsonRoundTripJourney;
use Vusys\NestedSet\Tests\TestCase;
use Vusys\Runabout\RunsJourneys;

/**
 * Runabout journey: toJsonTreeForest() -> wipe -> fromJsonTree()
 * reproduces the tree exactly (structure, sibling order, payload, and
 * rebuilt aggregates) under every shuffled ordering of grafts, moves,
 * root promotions, and round-trips. Where JsonImportRoundTripTest checks
 * one hand-built single-root tree, this round-trips fuzzed multi-root
 * forests and re-imports from already-imported state.
 * See {@see JsonRoundTripJourney}.
 */
#[Group('runabout')]
final class JsonRoundTripJourneyTest extends TestCase
{
    use RunsJourneys;

    #[Test]
    public function json_round_trips_reproduce_the_tree(): void
    {
        // repeatHeavy re-exports and re-imports the same forest, so later
        // round-trips import from an id-regenerated tree, not the original.
        $this->journey(JsonRoundTripJourney::class)
            ->repeatHeavy()
            ->shuffles(25)
            ->run();
    }
}
