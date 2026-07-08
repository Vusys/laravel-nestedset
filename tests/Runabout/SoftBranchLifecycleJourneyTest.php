<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Runabout;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Runabout\Journeys\SoftBranchLifecycleJourney;
use Vusys\NestedSet\Tests\TestCase;
use Vusys\Runabout\RunsJourneys;

/**
 * Runabout journey: cascade soft-delete / restore interleaved with
 * growth and source edits keeps aggregates and the tree intact and
 * never leaves a trashed parent holding a live child. See
 * {@see SoftBranchLifecycleJourney}.
 */
#[Group('runabout')]
final class SoftBranchLifecycleJourneyTest extends TestCase
{
    use RunsJourneys;

    #[Test]
    public function soft_delete_lifecycle_preserves_invariants(): void
    {
        $this->journey(SoftBranchLifecycleJourney::class)
            ->repeatHeavy()
            ->shuffles(25)
            ->run();
    }
}
