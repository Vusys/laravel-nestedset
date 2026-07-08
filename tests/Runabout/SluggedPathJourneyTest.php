<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Runabout;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Runabout\Journeys\SluggedPathJourney;
use Vusys\NestedSet\Tests\TestCase;
use Vusys\Runabout\RunsJourneys;

/**
 * Runabout journey: maintained slug paths stay coherent under every
 * shuffled ordering of subtree moves and renames. Where the materialised
 * -path fuzzer only moves leaves, this moves whole subtrees and renames
 * ancestors, so a maintenance path that rewrites only the moved row (or
 * only the renamed segment) drifts. See {@see SluggedPathJourney}.
 */
#[Group('runabout')]
final class SluggedPathJourneyTest extends TestCase
{
    use RunsJourneys;

    #[Test]
    public function slug_paths_stay_coherent_under_reordering(): void
    {
        // repeatHeavy re-runs moves and renames on the same nodes, which is
        // exactly how a stale cascade between the two paths surfaces.
        $this->journey(SluggedPathJourney::class)
            ->repeatHeavy()
            ->shuffles(25)
            ->run();
    }
}
