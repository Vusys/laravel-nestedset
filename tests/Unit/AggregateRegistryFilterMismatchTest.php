<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\AggregateDefinition;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\MismatchedFilterAvgArea;

/**
 * Locks in registry behaviour when a user declares Sum(x, filter=...)
 * AND Avg(x, no filter) over the same source. The AVG's companion
 * Sum must NOT be the user's filtered Sum — the values mean different
 * things ("sum of fire tickets" vs "sum of all tickets") and silently
 * sharing them would make the AVG display column read filtered data.
 */
final class AggregateRegistryFilterMismatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    public function test_avg_does_not_adopt_a_user_sum_with_a_different_filter(): void
    {
        $definitions = AggregateRegistry::for(MismatchedFilterAvgArea::class);

        // Registry must have auto-promoted an internal Sum (and Count)
        // that match the AVG's (null) filter — not picked up the
        // user's fire-filtered Sum as the companion.
        $companions = AggregateRegistry::avgCompanionsFor(MismatchedFilterAvgArea::class);

        $this->assertArrayHasKey('tickets_avg', $companions);
        $sumColumn = $companions['tickets_avg']['sum'];

        $this->assertNotSame(
            'fire_total',
            $sumColumn,
            "AVG must not adopt the user's filtered Sum 'fire_total' — the filters disagree.",
        );

        // The internal Sum companion is the one that matches the AVG's filter.
        $sumDefinition = null;
        foreach ($definitions as $definition) {
            if ($definition instanceof AggregateDefinition
                && $definition->column === $sumColumn) {
                $sumDefinition = $definition;
                break;
            }
        }

        $this->assertNotNull($sumDefinition, "companion Sum column '{$sumColumn}' must exist in the registry");
        $this->assertSame(AggregateFunction::Sum, $sumDefinition->function);
        $this->assertNull(
            $sumDefinition->filter,
            'Auto-promoted Sum must match the AVG: AVG has no filter, so its Sum companion must have no filter.',
        );
    }
}
