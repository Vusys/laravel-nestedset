<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Registry;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Definitions\CompanionSourceTransform;
use Vusys\NestedSet\Aggregates\Definitions\ListenerAggregateDefinition;
use Vusys\NestedSet\Aggregates\Strategy\LazyInvalidation;
use Vusys\NestedSet\Contracts\AggregateDefinitionContract;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * Cache + public lookup surface for the aggregate definitions declared
 * on a model class. Parsing lives in {@see AggregateAttributeParser};
 * companion auto-promotion and structural validation live in
 * {@see AggregateDefinitionValidator}; this class orchestrates the two
 * for `for()`, caches the result, and exposes per-function companion-
 * column lookups for the maintenance machinery.
 *
 * Resolution order (mirrors {@see NestedSetScopeResolver}):
 *   1. `#[NestedSetAggregate(...)]` attribute instances on the class.
 *   2. `nestedSetAggregates(): array` method override on the model.
 *   3. `#[NestedSetAggregateListener(...)]` attribute instances on the class.
 *   4. `nestedSetListenerAggregates(): array` method override on the model.
 *   5. Auto-promotion: each AVG declaration without explicit companion
 *      SUM and COUNT for the same source gets two internal companion
 *      definitions auto-added with predictable column names.
 *
 * Validation runs after merging and throws
 * {@see AggregateConfigurationException}
 * on any inconsistency (duplicate column targets, AVG declared with no
 * source, etc.).
 */
final class AggregateRegistry
{
    /**
     * Internal companion column suffix for the SUM half of an AVG.
     *
     * Retained for backwards compatibility — callers that need to
     * derive AVG companion column names by string concatenation can
     * use this constant. New code should ask the aggregate function
     * directly via {@see AggregateFunction::companionSet()} rather
     * than baking the convention into call sites.
     */
    public const string AVG_SUM_SUFFIX = '__sum';

    /**
     * Internal companion column suffix for the COUNT half of an AVG.
     *
     * See {@see self::AVG_SUM_SUFFIX} for the same caveat.
     */
    public const string AVG_COUNT_SUFFIX = '__count';

    /**
     * Cached resolved definitions, keyed by class name. Definitions for
     * a class never change after first resolution; caching avoids
     * repeated reflection on hot paths.
     *
     * @var array<class-string, list<AggregateDefinitionContract>>
     */
    private static array $cache = [];

    /**
     * Returns the merged, validated, auto-promoted set of definitions
     * for $class. Empty list means the model declares no aggregates.
     *
     * The list may contain both {@see AggregateDefinition} instances
     * (SQL-function aggregates) and — once registered — instances of
     * other {@see AggregateDefinitionContract} implementations. Callers
     * that need concrete properties must narrow with `instanceof`.
     *
     * @param  class-string<Model&HasNestedSet>  $class
     * @return list<AggregateDefinitionContract>
     */
    public static function for(string $class): array
    {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        $definitions = AggregateAttributeParser::parse($class);
        $definitions = AggregateDefinitionValidator::promoteAndValidate($class, $definitions);

        return self::$cache[$class] = $definitions;
    }

    /**
     * Clears the resolution cache. Tests should call this in setUp /
     * tearDown when they declare aggregates dynamically on anonymous
     * fixture classes.
     */
    public static function flush(): void
    {
        self::$cache = [];
    }

    /**
     * Returns invalidation specs for every lazy aggregate column declared
     * on `$class` — user-facing and internal companions alike (an internal
     * companion can never be lazy today, but the iteration is robust
     * either way). Each spec carries the value column name, its
     * `<column>_computed_at` stamp column, and the inclusivity flag
     * the {@see LazyInvalidation}
     * helper consumes to choose between `<=`/`>=` and `<`/`>` bounds
     * containment.
     *
     * @param  class-string<Model&HasNestedSet>  $class
     * @return list<array{column: string, stampColumn: string, inclusive: bool}>
     */
    public static function lazySpecsFor(string $class): array
    {
        $specs = [];
        foreach (self::for($class) as $definition) {
            if (! $definition->isLazy()) {
                continue;
            }
            $specs[] = [
                'column' => $definition->getColumn(),
                'stampColumn' => $definition->lazyStampColumn(),
                'inclusive' => $definition->isInclusive(),
            ];
        }

        return $specs;
    }

    /**
     * Returns the subset of {@see self::lazySpecsFor()} whose value column
     * appears in `$columnNames`. Used by mutation hooks (save / capture)
     * that already determined which lazy columns were affected by the
     * change.
     *
     * @param  class-string<Model&HasNestedSet>  $class
     * @param  list<string>  $columnNames
     * @return list<array{column: string, stampColumn: string, inclusive: bool}>
     */
    public static function lazySpecsForColumns(string $class, array $columnNames): array
    {
        if ($columnNames === []) {
            return [];
        }

        $wanted = array_flip($columnNames);

        return array_values(array_filter(
            self::lazySpecsFor($class),
            static fn (array $spec): bool => isset($wanted[$spec['column']]),
        ));
    }

    /**
     * For each inclusive AVG declaration on $class, returns the
     * column names of its companion SUM and COUNT definitions over
     * the same source. The result is consumed by Phase E maintenance
     * to write `avg = (sum + Δsum) / NULLIF(count + Δcount, 0)` in the
     * same UPDATE as the companion deltas.
     *
     * Companions are matched by source-column equality so user-declared
     * SUM/COUNT (e.g. `Aggregate::count('tickets')`) and auto-promoted
     * internal companions are treated uniformly. AVG declarations that
     * lack a matching pair are skipped — that state is unreachable when
     * declarations come through the registry's auto-promotion, but the
     * skip keeps the helper robust against direct
     * {@see AggregateDefinition} construction in tests.
     *
     * @param  class-string<Model&HasNestedSet>  $class
     * @return array<string, array{sum: string, count: string}>
     */
    public static function avgCompanionsFor(string $class): array
    {
        $definitions = self::for($class);
        $bySource = AggregateDefinitionValidator::indexBySource($definitions);
        $listenerBySource = AggregateDefinitionValidator::indexListenersByClassAndInclusive($definitions);

        $result = [];

        foreach ($definitions as $definition) {
            if ($definition instanceof AggregateDefinition) {
                if ($definition->function !== AggregateFunction::Avg) {
                    continue;
                }
                if ($definition->source === null) {
                    continue;
                }
                // Exclusive AVG is routed through the chain-recompute
                // path in the lifecycle hooks; it doesn't need the
                // delta-time SET clause.
                if (! $definition->inclusive) {
                    continue;
                }

                // Inclusivity must match too — an inclusive AVG that
                // silently adopts an exclusive Sum (or vice versa)
                // reads a different row set than its count companion
                // and produces drift the user can't see. Identity
                // transform is required too, otherwise a WeightedAvg's
                // TimesWeight companion (same source, same Sum function)
                // could be silently adopted as the AVG's numerator.
                $companions = $bySource[$definition->source] ?? [];
                $sumColumn = self::findFirstCompanion(
                    $companions,
                    $definition,
                    static fn (AggregateDefinition $c): bool => $c->function === AggregateFunction::Sum
                        && $c->sourceTransform === CompanionSourceTransform::Identity,
                )?->column;
                $countColumn = self::findFirstCompanion(
                    $companions,
                    $definition,
                    static fn (AggregateDefinition $c): bool => $c->function === AggregateFunction::Count
                        && $c->sourceTransform === CompanionSourceTransform::Identity,
                )?->column;

                if ($sumColumn !== null && $countColumn !== null) {
                    $result[$definition->column] = ['sum' => $sumColumn, 'count' => $countColumn];
                }

                continue;
            }

            if ($definition instanceof ListenerAggregateDefinition
                && $definition->operation === AggregateFunction::Avg) {
                if (! $definition->inclusive) {
                    continue;   // exclusive listener AVG handled via chain recompute
                }

                // Match Identity-transform companions with the same filter as
                // the parent AVG. Without the transform check, a sibling
                // Variance declaration's __sum_sq (Sum/Square) would qualify
                // as the AVG's numerator and feed sum-of-squares into the
                // mean formula.
                $key = $definition->listenerClass.'|inc';
                $companions = $listenerBySource[$key] ?? [];

                $sumColumn = null;
                $countColumn = null;
                foreach ($companions as $companion) {
                    if ($companion->lazy) {
                        continue;
                    }
                    if ($companion->sourceTransform !== CompanionSourceTransform::Identity) {
                        continue;
                    }
                    if (! AggregateDefinitionValidator::filtersMatch($companion->filter, $definition->filter)) {
                        continue;
                    }
                    if ($companion->operation === AggregateFunction::Sum && $sumColumn === null) {
                        $sumColumn = $companion->column;
                    }
                    if ($companion->operation === AggregateFunction::Count && $countColumn === null) {
                        $countColumn = $companion->column;
                    }
                }

                if ($sumColumn !== null && $countColumn !== null) {
                    $result[$definition->column] = ['sum' => $sumColumn, 'count' => $countColumn];
                }
            }
        }

        return $result;
    }

    /**
     * For each inclusive Variance / Stddev declaration on $class, returns
     * the column names of its Sum, SumSq, and Count companions plus the
     * sample/population flag. The result is consumed by Phase E
     * maintenance to write `variance = (n·SumSq − Sum²) / N` (with
     * `N = n²` for pop or `n(n−1)` for sample) in the same UPDATE as
     * the companion deltas.
     *
     * Companions are matched by source column + filter + inclusivity +
     * source transform (Sum vs SumSq differ only by transform). A
     * Variance declaration without a complete companion set is skipped
     * — that state is unreachable when declarations come through the
     * registry's auto-promotion, but the skip keeps the helper robust
     * against direct {@see AggregateDefinition} construction in tests.
     *
     * @param  class-string<Model&HasNestedSet>  $class
     * @return array<string, array{sum: string, sum_sq: string, count: string, function: AggregateFunction, sample: bool}>
     */
    public static function varianceCompanionsFor(string $class): array
    {
        $definitions = self::for($class);
        $bySource = AggregateDefinitionValidator::indexBySource($definitions);

        $result = [];

        foreach ($definitions as $definition) {
            if (! $definition instanceof AggregateDefinition) {
                continue;
            }
            if ($definition->function !== AggregateFunction::Variance
                && $definition->function !== AggregateFunction::Stddev) {
                continue;
            }
            if ($definition->source === null) {
                continue;
            }
            if (! $definition->inclusive) {
                continue;
            }

            $candidates = $bySource[$definition->source] ?? [];

            // First pass: prefer the canonical auto-promoted columns
            // (`<display>__sum`, `<display>__sum_sq`, `<display>__count`).
            // When the user has declared multiple Variance / Stddev
            // aggregates over the same source, each gets its own
            // companion triple under this naming convention — picking
            // the canonical one rather than the first match avoids
            // accidentally driving one display column off another's
            // companions.
            $sumColumn = self::findCanonicalCompanion($candidates, $definition, AggregateFunction::Sum, CompanionSourceTransform::Identity, '__sum');
            $sumSqColumn = self::findCanonicalCompanion($candidates, $definition, AggregateFunction::Sum, CompanionSourceTransform::Square, '__sum_sq');
            $countColumn = self::findCanonicalCompanion($candidates, $definition, AggregateFunction::Count, CompanionSourceTransform::Identity, '__count');

            // Second pass for any companion the canonical lookup
            // missed — allows power users to satisfy a Variance /
            // Stddev's companion requirement with a hand-declared
            // Sum / Count that happens to compute the same value.
            // Order-sensitive: takes the first compatible match.
            $sumColumn ??= self::findFirstCompanion(
                $candidates,
                $definition,
                static fn (AggregateDefinition $c): bool => $c->function === AggregateFunction::Sum
                    && $c->sourceTransform === CompanionSourceTransform::Identity,
            )?->column;
            $sumSqColumn ??= self::findFirstCompanion(
                $candidates,
                $definition,
                static fn (AggregateDefinition $c): bool => $c->function === AggregateFunction::Sum
                    && $c->sourceTransform === CompanionSourceTransform::Square,
            )?->column;
            $countColumn ??= self::findFirstCompanion(
                $candidates,
                $definition,
                static fn (AggregateDefinition $c): bool => $c->function === AggregateFunction::Count
                    && $c->sourceTransform === CompanionSourceTransform::Identity,
            )?->column;

            if ($sumColumn !== null && $sumSqColumn !== null && $countColumn !== null) {
                $result[$definition->column] = [
                    'sum' => $sumColumn,
                    'sum_sq' => $sumSqColumn,
                    'count' => $countColumn,
                    'function' => $definition->function,
                    'sample' => $definition->sample,
                ];
            }
        }

        return $result;
    }

    /**
     * For each inclusive WeightedAvg declaration on $class, returns the
     * companion column names ({sum_wx, sum_w}) used at delta-time to
     * derive the display value. Exclusive weighted averages are
     * maintained via the chain-recompute path and don't need this
     * helper.
     *
     * @param  class-string<Model&HasNestedSet>  $class
     * @return array<string, array{sum_wx: string, sum_w: string}>
     */
    public static function weightedAvgCompanionsFor(string $class): array
    {
        $definitions = self::for($class);
        $bySource = AggregateDefinitionValidator::indexBySource($definitions);

        $result = [];

        foreach ($definitions as $definition) {
            if (! $definition instanceof AggregateDefinition) {
                continue;
            }
            if ($definition->function !== AggregateFunction::WeightedAvg) {
                continue;
            }
            if (! $definition->inclusive) {
                continue;
            }
            if ($definition->source === null) {
                continue;
            }
            if ($definition->weight === null) {
                continue;
            }

            // Weight column must match too — two WeightedAvg declarations
            // over the same source with different weight columns each
            // produce their own TimesWeight Sum companion, and adopting
            // the wrong one would silently feed the AVG-of-products the
            // wrong weights.
            $sumWxColumn = self::findFirstCompanion(
                $bySource[$definition->source] ?? [],
                $definition,
                static fn (AggregateDefinition $c): bool => $c->function === AggregateFunction::Sum
                    && $c->sourceTransform === CompanionSourceTransform::TimesWeight
                    && $c->weight === $definition->weight,
            )?->column;
            $sumWColumn = self::findFirstCompanion(
                $bySource[$definition->weight] ?? [],
                $definition,
                static fn (AggregateDefinition $c): bool => $c->function === AggregateFunction::Sum
                    && $c->sourceTransform === CompanionSourceTransform::Identity,
            )?->column;

            if ($sumWxColumn !== null && $sumWColumn !== null) {
                $result[$definition->column] = ['sum_wx' => $sumWxColumn, 'sum_w' => $sumWColumn];
            }
        }

        return $result;
    }

    /**
     * For each inclusive BoolOr / BoolAnd declaration on $class,
     * returns the companion column names ({sum, count}) plus the
     * function so the delta-time SET clause can pick the right
     * `Sum > 0` / `Sum = Count` formula.
     *
     * @param  class-string<Model&HasNestedSet>  $class
     * @return array<string, array{sum: string, count: string, function: AggregateFunction}>
     */
    public static function boolCompanionsFor(string $class): array
    {
        $definitions = self::for($class);
        $bySource = AggregateDefinitionValidator::indexBySource($definitions);

        $result = [];

        foreach ($definitions as $definition) {
            if (! $definition instanceof AggregateDefinition) {
                continue;
            }
            if ($definition->function !== AggregateFunction::BoolOr
                && $definition->function !== AggregateFunction::BoolAnd) {
                continue;
            }
            if (! $definition->inclusive) {
                continue;
            }
            if ($definition->source === null) {
                continue;
            }

            $candidates = $bySource[$definition->source] ?? [];
            $sumColumn = self::findFirstCompanion(
                $candidates,
                $definition,
                static fn (AggregateDefinition $c): bool => $c->function === AggregateFunction::Sum
                    && $c->sourceTransform === CompanionSourceTransform::AsInt,
            )?->column;
            $countColumn = self::findFirstCompanion(
                $candidates,
                $definition,
                static fn (AggregateDefinition $c): bool => $c->function === AggregateFunction::Count
                    && $c->sourceTransform === CompanionSourceTransform::Identity,
            )?->column;

            if ($sumColumn !== null && $countColumn !== null) {
                $result[$definition->column] = [
                    'sum' => $sumColumn,
                    'count' => $countColumn,
                    'function' => $definition->function,
                ];
            }
        }

        return $result;
    }

    /**
     * For each inclusive GeometricMean / HarmonicMean declaration on
     * $class, returns the companion column names needed at delta time to
     * derive the display value. Shape:
     *  `{displayCol} => {sum_log|sum_recip: string, count: string, function: AggregateFunction}`
     *
     * @param  class-string<Model&HasNestedSet>  $class
     * @return array<string, array{sum_companion: string, count: string, function: AggregateFunction, allowNonPositive: bool}>
     */
    public static function meanCompanionsFor(string $class): array
    {
        $definitions = self::for($class);
        $bySource = AggregateDefinitionValidator::indexBySource($definitions);

        $result = [];

        foreach ($definitions as $definition) {
            if (! $definition instanceof AggregateDefinition) {
                continue;
            }
            if ($definition->function !== AggregateFunction::GeometricMean
                && $definition->function !== AggregateFunction::HarmonicMean) {
                continue;
            }
            if (! $definition->inclusive) {
                continue;
            }
            if ($definition->source === null) {
                continue;
            }

            $companionTransform = $definition->function === AggregateFunction::GeometricMean
                ? CompanionSourceTransform::Ln
                : CompanionSourceTransform::Recip;

            // The `<displayCol>__` prefix anchors companions to *this*
            // mean — two Geometric/HarmonicMeans over the same source
            // each get their own auto-promoted pair, so loose matching
            // would silently bind one display column to another's.
            $candidates = $bySource[$definition->source] ?? [];
            $columnPrefix = $definition->column.'__';
            $sumCompanionColumn = self::findFirstCompanion(
                $candidates,
                $definition,
                static fn (AggregateDefinition $c): bool => $c->function === AggregateFunction::Sum
                    && $c->sourceTransform === $companionTransform
                    && str_starts_with($c->column, $columnPrefix),
            )?->column;
            $countColumn = self::findFirstCompanion(
                $candidates,
                $definition,
                static fn (AggregateDefinition $c): bool => $c->function === AggregateFunction::Count
                    && $c->sourceTransform === $companionTransform
                    && str_starts_with($c->column, $columnPrefix),
            )?->column;

            if ($sumCompanionColumn !== null && $countColumn !== null) {
                $result[$definition->column] = [
                    'sum_companion' => $sumCompanionColumn,
                    'count' => $countColumn,
                    'function' => $definition->function,
                    'allowNonPositive' => $definition->allowNonPositive,
                ];
            }
        }

        return $result;
    }

    /**
     * Return the candidate companion column matching the canonical
     * naming convention `<displayColumn><suffix>` — used by
     * {@see varianceCompanionsFor()} to pick the companion that was
     * auto-promoted *for this specific display column*, not any other
     * Variance / Stddev sharing the same source.
     *
     * @param  list<AggregateDefinition>  $candidates
     */
    private static function findCanonicalCompanion(
        array $candidates,
        AggregateDefinition $parent,
        AggregateFunction $function,
        CompanionSourceTransform $transform,
        string $suffix,
    ): ?string {
        $expected = $parent->column.$suffix;

        return self::findFirstCompanion(
            $candidates,
            $parent,
            static fn (AggregateDefinition $c): bool => $c->column === $expected
                && $c->function === $function
                && $c->sourceTransform === $transform,
        )?->column;
    }

    /**
     * Scan $candidates for the first definition that (a) shares
     * $parent's filter + inclusivity (the universal companion-validity
     * rule — companions reading a different row set than the parent
     * would silently produce drift) and (b) satisfies $extra (the
     * caller's role-specific predicate, e.g. "Sum with Identity
     * transform"). Returns the matched definition or null.
     *
     * Folds the inner foreach shared by every `*CompanionsFor()`
     * helper so the methods stay focused on what makes them different
     * (which functions are parents, which roles they need filled,
     * what shape the result takes).
     *
     * @param  list<AggregateDefinition>  $candidates
     * @param  callable(AggregateDefinition): bool  $extra
     */
    private static function findFirstCompanion(
        array $candidates,
        AggregateDefinition $parent,
        callable $extra,
    ): ?AggregateDefinition {
        foreach ($candidates as $candidate) {
            if ($candidate->inclusive !== $parent->inclusive) {
                continue;
            }
            if (! AggregateDefinitionValidator::filtersMatch($candidate->filter, $parent->filter)) {
                continue;
            }
            // Lazy candidates are NULL between mutations; adopting one as
            // a companion-derived display column's input would feed the
            // derived formula a NULL value and silently break the parent.
            // Auto-promotion creates a non-lazy internal companion
            // alongside in this case.
            if ($candidate->lazy) {
                continue;
            }
            if (! $extra($candidate)) {
                continue;
            }

            return $candidate;
        }

        return null;
    }
}
