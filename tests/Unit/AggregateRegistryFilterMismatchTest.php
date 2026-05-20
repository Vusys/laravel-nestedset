<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\AggregateDefinition;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\AvgWithCompanionsArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\AvgWithMatchingEqualityFilterArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\AvgWithMatchingNotNullFilterArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\AvgWithMatchingRawFilterArea;
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

    // ----------------------------------------------------------------
    // Positive-match cases: when filters DO agree, the registry must
    // adopt the user's Sum / Count as the AVG companions (no auto-
    // promoted internals). Each case below pins one branch of
    // `AggregateRegistry::filtersMatch()`:
    //
    //   - both filters null  → `! a instanceof FP && ! b instanceof FP`
    //   - both Equality      → match arm `FilterPredicateKind::Equality`
    //   - both NotNull       → match arm `FilterPredicateKind::NotNull`
    //   - both Raw           → match arm `FilterPredicateKind::Raw`
    // ----------------------------------------------------------------

    public function test_avg_adopts_user_companions_when_neither_has_a_filter(): void
    {
        $companions = AggregateRegistry::avgCompanionsFor(AvgWithCompanionsArea::class);

        // User declared `tickets_sum`, `tickets_count`, and `tickets_avg`,
        // all with no filter. Registry must wire AVG to the user's
        // Sum and Count rather than auto-promote internals.
        $this->assertSame(
            ['sum' => 'tickets_sum', 'count' => 'tickets_count'],
            $companions['tickets_avg'] ?? null,
        );
    }

    public function test_avg_adopts_user_companions_when_filters_share_equality_predicate(): void
    {
        $companions = AggregateRegistry::avgCompanionsFor(AvgWithMatchingEqualityFilterArea::class);

        $this->assertSame(
            ['sum' => 'fire_total', 'count' => 'fire_count'],
            $companions['fire_avg'] ?? null,
        );
    }

    public function test_avg_adopts_user_companions_when_filters_share_not_null_predicate(): void
    {
        $companions = AggregateRegistry::avgCompanionsFor(AvgWithMatchingNotNullFilterArea::class);

        $this->assertSame(
            ['sum' => 'type_total', 'count' => 'type_count'],
            $companions['type_avg'] ?? null,
        );
    }

    public function test_avg_adopts_user_companions_when_filters_share_raw_predicate(): void
    {
        $companions = AggregateRegistry::avgCompanionsFor(AvgWithMatchingRawFilterArea::class);

        $this->assertSame(
            ['sum' => 'typed_total', 'count' => 'typed_count'],
            $companions['typed_avg'] ?? null,
        );
    }
}
