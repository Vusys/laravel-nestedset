<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Aggregates\Registry;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\AvgWithCompanionsArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\AvgWithMatchingEqualityFilterArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\AvgWithMatchingNotNullFilterArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\AvgWithMatchingRawFilterArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\MismatchedFilterAvgArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\MismatchedInclusiveAvgArea;

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

    #[Test]
    public function avg_does_not_adopt_a_user_sum_with_a_different_filter(): void
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
    // `AggregateDefinitionValidator::filtersMatch()`:
    //
    //   - both filters null  → `! a instanceof FP && ! b instanceof FP`
    //   - both Equality      → match arm `FilterPredicateKind::Equality`
    //   - both NotNull       → match arm `FilterPredicateKind::NotNull`
    //   - both Raw           → match arm `FilterPredicateKind::Raw`
    // ----------------------------------------------------------------

    #[Test]
    public function avg_adopts_user_companions_when_neither_has_a_filter(): void
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

    #[Test]
    public function avg_adopts_user_companions_when_filters_share_equality_predicate(): void
    {
        $companions = AggregateRegistry::avgCompanionsFor(AvgWithMatchingEqualityFilterArea::class);

        $this->assertSame(
            ['sum' => 'fire_total', 'count' => 'fire_count'],
            $companions['fire_avg'] ?? null,
        );
    }

    #[Test]
    public function avg_adopts_user_companions_when_filters_share_not_null_predicate(): void
    {
        $companions = AggregateRegistry::avgCompanionsFor(AvgWithMatchingNotNullFilterArea::class);

        $this->assertSame(
            ['sum' => 'type_total', 'count' => 'type_count'],
            $companions['type_avg'] ?? null,
        );
    }

    #[Test]
    public function avg_adopts_user_companions_when_filters_share_raw_predicate(): void
    {
        $companions = AggregateRegistry::avgCompanionsFor(AvgWithMatchingRawFilterArea::class);

        $this->assertSame(
            ['sum' => 'typed_total', 'count' => 'typed_count'],
            $companions['typed_avg'] ?? null,
        );
    }

    #[Test]
    public function avg_does_not_adopt_a_user_sum_with_different_inclusivity(): void
    {
        $definitions = AggregateRegistry::for(MismatchedInclusiveAvgArea::class);
        $companions = AggregateRegistry::avgCompanionsFor(MismatchedInclusiveAvgArea::class);

        // The user's exclusive Sum must not become the inclusive AVG's
        // companion — different inclusivity = different row set.
        $this->assertArrayHasKey('tickets_avg', $companions);
        $sumColumn = $companions['tickets_avg']['sum'];

        $this->assertNotSame('tickets_sum_exc', $sumColumn);

        $sumDefinition = $this->findColumnDefinition($definitions, $sumColumn);

        $this->assertNotNull($sumDefinition, "auto-promoted Sum '{$sumColumn}' must exist");
        $this->assertTrue(
            $sumDefinition->inclusive,
            'Auto-promoted Sum must match the inclusive AVG, not adopt the exclusive declaration.',
        );
    }

    #[Test]
    public function avg_does_not_adopt_a_user_count_with_different_inclusivity(): void
    {
        $definitions = AggregateRegistry::for(MismatchedInclusiveAvgArea::class);
        $companions = AggregateRegistry::avgCompanionsFor(MismatchedInclusiveAvgArea::class);

        // Mirror of the Sum-side guard: the user's exclusive Count
        // must not be adopted as the inclusive AVG's Count companion.
        $this->assertArrayHasKey('tickets_avg', $companions);
        $countColumn = $companions['tickets_avg']['count'];

        $this->assertNotSame('tickets_count_exc', $countColumn);

        $countDefinition = $this->findColumnDefinition($definitions, $countColumn);

        $this->assertNotNull($countDefinition, "auto-promoted Count '{$countColumn}' must exist");
        $this->assertTrue(
            $countDefinition->inclusive,
            'Auto-promoted Count must match the inclusive AVG, not adopt the exclusive declaration.',
        );
    }

    /**
     * @param  iterable<AggregateDefinition|object>  $definitions
     */
    private function findColumnDefinition(iterable $definitions, string $column): ?AggregateDefinition
    {
        foreach ($definitions as $definition) {
            if ($definition instanceof AggregateDefinition && $definition->column === $column) {
                return $definition;
            }
        }

        return null;
    }
}
