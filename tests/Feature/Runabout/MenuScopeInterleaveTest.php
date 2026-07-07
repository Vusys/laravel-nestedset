<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Runabout;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Feature\Runabout\Journeys\MenuScopeJourney;
use Vusys\NestedSet\Tests\TestCase;
use Vusys\Runabout\RunsJourneys;

/**
 * Runabout interleave: two scoped MenuItem trees mutated in one merged
 * trail. A write in one menu that forgets its scope leaks into the
 * other, which the isolation invariant catches after the offending
 * step. See {@see MenuScopeJourney}.
 */
#[Group('runabout')]
final class MenuScopeInterleaveTest extends TestCase
{
    use RunsJourneys;

    #[Test]
    public function scoped_trees_stay_isolated_when_interleaved(): void
    {
        $this->interleave(new MenuScopeJourney('A'), new MenuScopeJourney('B'))
            ->shuffles(25)
            ->run();
    }
}
