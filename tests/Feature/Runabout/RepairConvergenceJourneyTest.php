<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Runabout;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Feature\Runabout\Journeys\RepairConvergenceJourney;
use Vusys\NestedSet\Tests\TestCase;
use Vusys\Runabout\RunsJourneys;

/**
 * Runabout journey: fixTree() rebuilds the tree from parent_id and
 * converges to the same id-ordered structure under every shuffled
 * ordering of mutations and raw-SQL corruption. Where the Corruption/
 * tests each repair one hand-built corruption, this interleaves
 * corruption patterns with real moves and reorders and checks the
 * rebuild against an independent parent_id reconstruction every time.
 * See {@see RepairConvergenceJourney}.
 */
#[Group('runabout')]
final class RepairConvergenceJourneyTest extends TestCase
{
    use RunsJourneys;

    #[Test]
    public function fix_tree_converges_from_parent_id(): void
    {
        // repeatHeavy re-corrupts and re-repairs the same tree, growing the
        // divergence between lft order and id order the rebuild must resolve.
        $this->journey(RepairConvergenceJourney::class)
            ->repeatHeavy()
            ->shuffles(25)
            ->run();
    }
}
