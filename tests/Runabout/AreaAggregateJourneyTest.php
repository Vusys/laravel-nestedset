<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Runabout;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Runabout\Journeys\AreaAggregateJourney;
use Vusys\NestedSet\Tests\TestCase;
use Vusys\Runabout\RunsJourneys;

/**
 * Runabout journey: aggregate columns stay correct under every shuffled
 * ordering of moves and source edits. See {@see AreaAggregateJourney}.
 */
#[Group('runabout')]
final class AreaAggregateJourneyTest extends TestCase
{
    use RunsJourneys;

    #[Test]
    public function aggregates_stay_correct_under_reordering(): void
    {
        // repeatHeavy is the idempotency hunter: re-running source edits
        // and moves is exactly how delta/recompute drift surfaces.
        $this->journey(AreaAggregateJourney::class)
            ->repeatHeavy()
            ->shuffles(25)
            ->run();
    }
}
