<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Runabout;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Feature\Runabout\Journeys\DiffAddRemoveJourney;
use Vusys\NestedSet\Tests\TestCase;
use Vusys\Runabout\RunsJourneys;

/**
 * Runabout journey: a full add/move/remove/modify diff keyed on a
 * unique name, and its recomputed inverse, round-trip a tree back to
 * its exact starting shape under every shuffled ordering. This drives
 * the applier's four-phase ordering (adds before moves before removes
 * before modifies) that DiffRoundTripJourney's moves-only window can't
 * reach. See {@see DiffAddRemoveJourney}.
 */
#[Group('runabout')]
final class DiffAddRemoveJourneyTest extends TestCase
{
    use RunsJourneys;

    #[Test]
    public function full_diffs_round_trip_the_tree(): void
    {
        // repeatHeavy re-churns the same tree, so successive round-trips
        // diff against ever-more-tangled add/remove/move combinations.
        $this->journey(DiffAddRemoveJourney::class)
            ->repeatHeavy()
            ->shuffles(25)
            ->run();
    }
}
