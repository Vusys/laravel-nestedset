<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Runabout;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Feature\Runabout\Journeys\MonsterHordeJourney;
use Vusys\NestedSet\Tests\TestCase;
use Vusys\Runabout\RunsJourneys;

/**
 * Runabout journey: listener aggregates (including an exclusive column and
 * MIN/MAX) plus cascade soft-delete stay correct under every shuffled
 * ordering of moves, source edits, and trash / restore. Complements the
 * SQL-aggregate {@see AreaAggregateJourneyTest} on the separate listener
 * maintenance path. See {@see MonsterHordeJourney}.
 */
#[Group('runabout')]
final class MonsterHordeJourneyTest extends TestCase
{
    use RunsJourneys;

    #[Test]
    public function listener_aggregates_stay_correct_under_reordering(): void
    {
        $this->journey(MonsterHordeJourney::class)
            ->repeatHeavy()
            ->shuffles(25)
            ->run();
    }
}
