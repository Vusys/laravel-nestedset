<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Runabout;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Runabout\Journeys\DiffRoundTripJourney;
use Vusys\NestedSet\Tests\TestCase;
use Vusys\Runabout\RunsJourneys;

/**
 * Runabout journey: a moves-and-modifies diff and its directly
 * recomputed inverse round-trip a tree back to its exact starting
 * shape under every shuffled ordering of grafts, retitles, and
 * round-trips. Where the Diff/ unit tests apply one hand-built diff,
 * this feeds apply() a diff derived from two arbitrary fuzzed states,
 * exercising the phase-ordering and simultaneous-move logic.
 * See {@see DiffRoundTripJourney}.
 */
#[Group('runabout')]
final class DiffRoundTripJourneyTest extends TestCase
{
    use RunsJourneys;

    #[Test]
    public function inverse_diffs_round_trip_the_tree(): void
    {
        // repeatHeavy re-churns the same nodes across round-trips, growing
        // the simultaneous-move count a single pass never reaches.
        $this->journey(DiffRoundTripJourney::class)
            ->repeatHeavy()
            ->shuffles(25)
            ->run();
    }
}
