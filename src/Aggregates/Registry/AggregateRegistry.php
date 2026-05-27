<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Registry;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Definitions\CompanionSourceOrigin;
use Vusys\NestedSet\Aggregates\Definitions\CompanionSourceTransform;
use Vusys\NestedSet\Aggregates\Definitions\CompanionSpec;
use Vusys\NestedSet\Aggregates\Definitions\ListenerAggregateDefinition;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicate;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicateKind;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Attributes\NestedSetAggregateListener;
use Vusys\NestedSet\Contracts\AggregateDefinitionContract;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * Resolves the full set of aggregate definitions declared for a model
 * class, merging attribute declarations with any method override.
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
 * {@see AggregateConfigurationException} on any inconsistency (duplicate
 * column targets, AVG declared with no source, etc.).
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

        /** @var list<AggregateDefinitionContract> $definitions */
        $definitions = array_merge(
            self::fromAttributes($class),
            self::fromMethodOverride($class),
            self::fromListenerAttributes($class),
            self::fromListenerMethodOverride($class),
        );

        $definitions = self::autoPromoteCompanions($definitions);

        self::assertNoDuplicateColumns($definitions, $class);
        self::assertNoAggregateColumnsInFillable($definitions, $class);
        self::assertAggregateColumnsAreMassAssignmentSafe($definitions, $class);

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
        $bySource = self::indexBySource($definitions);
        $listenerBySource = self::indexListenersByClassAndInclusive($definitions);

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
                // and produces drift the user can't see.
                $companions = $bySource[$definition->source] ?? [];
                $sumColumn = self::findFirstCompanion(
                    $companions,
                    $definition,
                    static fn (AggregateDefinition $c): bool => $c->function === AggregateFunction::Sum,
                )?->column;
                $countColumn = self::findFirstCompanion(
                    $companions,
                    $definition,
                    static fn (AggregateDefinition $c): bool => $c->function === AggregateFunction::Count,
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

                $key = $definition->listenerClass.'|inc';
                $companions = $listenerBySource[$key] ?? [];

                $sumColumn = null;
                $countColumn = null;
                foreach ($companions as $companion) {
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
        $bySource = self::indexBySource($definitions);

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
        $bySource = self::indexBySource($definitions);

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

            $sumWxColumn = self::findFirstCompanion(
                $bySource[$definition->source] ?? [],
                $definition,
                static fn (AggregateDefinition $c): bool => $c->function === AggregateFunction::Sum
                    && $c->sourceTransform === CompanionSourceTransform::TimesWeight,
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
        $bySource = self::indexBySource($definitions);

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
            if (! self::filtersMatch($candidate->filter, $parent->filter)) {
                continue;
            }
            if (! $extra($candidate)) {
                continue;
            }

            return $candidate;
        }

        return null;
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
        $bySource = self::indexBySource($definitions);

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
     * @param  class-string<Model&HasNestedSet>  $class
     * @return list<AggregateDefinition>
     */
    private static function fromAttributes(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $attributes = $reflection->getAttributes(NestedSetAggregate::class);

        $definitions = [];

        foreach ($attributes as $attribute) {
            $definitions[] = $attribute->newInstance()->toDefinition();
        }

        return $definitions;
    }

    /**
     * @param  class-string<Model&HasNestedSet>  $class
     * @return list<AggregateDefinition>
     */
    private static function fromMethodOverride(string $class): array
    {
        if (! method_exists($class, 'nestedSetAggregates')) {
            return [];
        }

        // Reflection invocation because the method is declared protected
        // on user models (mirrors the pattern in NestedSetScopeResolver
        // for getScopeAttributes()). Eloquent models are safe to
        // construct without args for class-level metadata reads.
        $instance = new $class;
        $method = (new ReflectionClass($class))->getMethod('nestedSetAggregates');
        $raw = $method->invoke($instance);

        if (! is_array($raw)) {
            throw new AggregateConfigurationException(sprintf(
                '%s::nestedSetAggregates() must return an array of AggregateDefinition.',
                $class,
            ));
        }

        $definitions = [];

        foreach ($raw as $index => $definition) {
            if (! $definition instanceof AggregateDefinition) {
                throw new AggregateConfigurationException(sprintf(
                    '%s::nestedSetAggregates(): entry at index %s is not an AggregateDefinition. '
                    .'Use Aggregate::sum(...)->into(...) to produce one.',
                    $class,
                    (string) $index,
                ));
            }

            $definitions[] = $definition;
        }

        return $definitions;
    }

    /**
     * @param  class-string<Model&HasNestedSet>  $class
     * @return list<ListenerAggregateDefinition>
     */
    private static function fromListenerAttributes(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $attributes = $reflection->getAttributes(NestedSetAggregateListener::class);

        $definitions = [];

        foreach ($attributes as $attribute) {
            $definitions[] = $attribute->newInstance()->toDefinition();
        }

        return $definitions;
    }

    /**
     * @param  class-string<Model&HasNestedSet>  $class
     * @return list<ListenerAggregateDefinition>
     */
    private static function fromListenerMethodOverride(string $class): array
    {
        if (! method_exists($class, 'nestedSetListenerAggregates')) {
            return [];
        }

        $instance = new $class;
        $method = (new ReflectionClass($class))->getMethod('nestedSetListenerAggregates');
        $raw = $method->invoke($instance);

        if (! is_array($raw)) {
            throw new AggregateConfigurationException(sprintf(
                '%s::nestedSetListenerAggregates() must return an array of ListenerAggregateDefinition.',
                $class,
            ));
        }

        $definitions = [];

        foreach ($raw as $index => $definition) {
            if (! $definition instanceof ListenerAggregateDefinition) {
                throw new AggregateConfigurationException(sprintf(
                    '%s::nestedSetListenerAggregates(): entry at index %s is not a ListenerAggregateDefinition. '
                    .'Use ListenerAggregate::sum(...)->into(...) to produce one.',
                    $class,
                    (string) $index,
                ));
            }

            $definitions[] = $definition;
        }

        return $definitions;
    }

    /**
     * For each aggregate definition that declares a non-empty
     * {@see AggregateFunction::companionSet()}, adds the missing
     * companion definitions as `internal: true`. Companions inherit
     * the parent's source, inclusivity, and filter predicate so they
     * stay semantically aligned with the user-facing aggregate they
     * support.
     *
     * Skip rule: if a user has already declared a matching companion
     * (same function, same source, equivalent filter), the existing
     * declaration is adopted and no internal duplicate is added. Filter
     * equivalence is decided by {@see self::filtersMatch()} — a Sum
     * with a different predicate than the AVG would silently read a
     * different row set and is therefore not a valid companion.
     *
     * Non-{@see AggregateDefinition} entries (e.g. {@see ListenerAggregateDefinition})
     * follow an analogous path keyed on listener class + inclusivity.
     *
     * @param  list<AggregateDefinitionContract>  $definitions
     * @return list<AggregateDefinitionContract>
     */
    private static function autoPromoteCompanions(array $definitions): array
    {
        $bySource = self::indexBySource($definitions);
        $listenerBySource = self::indexListenersByClassAndInclusive($definitions);

        /** @var list<AggregateDefinitionContract> $extras */
        $extras = [];

        foreach ($definitions as $definition) {
            if ($definition instanceof AggregateDefinition) {
                $companionSet = $definition->function->companionSet();
                if ($companionSet === []) {
                    continue;
                }

                if ($definition->source === null) {
                    throw new AggregateConfigurationException(sprintf(
                        'AggregateDefinition for column "%s": %s requires a source column.',
                        $definition->column,
                        strtoupper($definition->function->value),
                    ));
                }

                foreach ($companionSet as $spec) {
                    $companionSource = self::resolveCompanionSource($spec, $definition);

                    // Transforms other than Identity emit a derived
                    // expression in the SUM (e.g. `source * source` for
                    // variance, `weight * value` for weightedAvg,
                    // `CASE WHEN bool THEN 1 ELSE 0 END` for
                    // boolOr/boolAnd). A user-declared plain `Sum` on
                    // the same column means something different —
                    // never adopt it. Always create an internal
                    // companion.
                    $allowAdoption = $spec->sourceTransform === CompanionSourceTransform::Identity;

                    if ($allowAdoption) {
                        $companionsForSource = $bySource[$companionSource] ?? [];
                        // Only candidates whose filter AND inclusivity
                        // match the parent count as valid companions.
                        // A different filter would silently feed the
                        // parent filtered data; a different inclusivity
                        // would feed it a different row set.
                        $companionsForSource = array_values(array_filter(
                            $companionsForSource,
                            static fn (AggregateDefinition $candidate): bool => self::filtersMatch(
                                $candidate->filter,
                                $definition->filter,
                            ) && $candidate->inclusive === $definition->inclusive
                                && $candidate->sourceTransform === CompanionSourceTransform::Identity,
                        ));

                        if (self::hasCompanion($companionsForSource, $spec)) {
                            continue;
                        }
                    }

                    $extras[] = new AggregateDefinition(
                        column: $spec->columnFor($definition->column),
                        function: $spec->function,
                        source: $companionSource,
                        inclusive: $definition->inclusive,
                        internal: true,
                        filter: $definition->filter,
                        sourceTransform: $spec->sourceTransform,
                        weight: $spec->sourceTransform->requiresWeight() ? $definition->weight : null,
                    );
                }

                continue;
            }

            if ($definition instanceof ListenerAggregateDefinition) {
                $companionSet = $definition->operation->companionSet();
                if ($companionSet === []) {
                    continue;
                }

                $key = $definition->listenerClass.'|'.($definition->inclusive ? 'inc' : 'exc');
                $companions = $listenerBySource[$key] ?? [];

                foreach ($companionSet as $spec) {
                    $alreadyDeclared = false;
                    foreach ($companions as $companion) {
                        if ($companion->operation === $spec->function) {
                            $alreadyDeclared = true;
                            break;
                        }
                    }

                    if ($alreadyDeclared) {
                        continue;
                    }

                    $extras[] = new ListenerAggregateDefinition(
                        column: $spec->columnFor($definition->column),
                        listenerClass: $definition->listenerClass,
                        operation: $spec->function,
                        inclusive: $definition->inclusive,
                        internal: true,
                    );
                }
            }
        }

        return array_merge($definitions, $extras);
    }

    /**
     * Resolves a {@see CompanionSpec}'s effective source column on a
     * parent aggregate. The companion takes the parent's primary
     * source by default; weighted average's `Sum(weight)` companion
     * uses {@see CompanionSourceOrigin::ParentWeight} to draw from
     * the parent's weight column instead.
     */
    private static function resolveCompanionSource(CompanionSpec $spec, AggregateDefinition $parent): string
    {
        return match ($spec->sourceOrigin) {
            CompanionSourceOrigin::ParentSource => $parent->source ?? throw new AggregateConfigurationException(sprintf(
                'AggregateDefinition for column "%s": %s requires a source column for its %s companion.',
                $parent->column,
                strtoupper($parent->function->value),
                $spec->function->value,
            )),
            CompanionSourceOrigin::ParentWeight => $parent->weight ?? throw new AggregateConfigurationException(sprintf(
                'AggregateDefinition for column "%s": %s declared a companion drawing from the weight column, '
                .'but no weight column was set on the parent definition.',
                $parent->column,
                strtoupper($parent->function->value),
            )),
        };
    }

    /**
     * Group listener definitions by `listenerClass|inclusive` so AVG
     * auto-promotion can detect already-declared Sum/Count companions
     * (a user might declare Sum + Count + Avg manually for the same
     * listener; we skip promotion in that case).
     *
     * @param  list<AggregateDefinitionContract>  $definitions
     * @return array<string, list<ListenerAggregateDefinition>>
     */
    private static function indexListenersByClassAndInclusive(array $definitions): array
    {
        $index = [];

        foreach ($definitions as $definition) {
            if (! $definition instanceof ListenerAggregateDefinition) {
                continue;
            }
            $key = $definition->listenerClass.'|'.($definition->inclusive ? 'inc' : 'exc');
            $index[$key][] = $definition;
        }

        return $index;
    }

    /**
     * @param  list<AggregateDefinitionContract>  $definitions
     * @return array<string, list<AggregateDefinition>>
     */
    private static function indexBySource(array $definitions): array
    {
        $index = [];

        foreach ($definitions as $definition) {
            if (! $definition instanceof AggregateDefinition) {
                continue;
            }
            if ($definition->source === null) {
                continue;
            }
            $index[$definition->source][] = $definition;
        }

        return $index;
    }

    /**
     * True when one of $definitions matches the given companion spec —
     * same underlying function AND same source transformation.
     *
     * Source transform must match because two `Sum` companions over
     * the same source column may carry different transforms — e.g.
     * Variance needs one identity-Sum and one square-Sum companion;
     * picking the wrong one (identity counted as the SumSq companion)
     * would silently store the wrong value.
     *
     * @param  list<AggregateDefinition>  $definitions
     */
    private static function hasCompanion(array $definitions, CompanionSpec $spec): bool
    {
        foreach ($definitions as $definition) {
            if ($definition->function === $spec->function
                && $definition->sourceTransform === $spec->sourceTransform) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when two filter predicates would select the same rows. Used
     * to decide whether a user-declared Sum / Count is a semantically-
     * correct companion for an AVG. Sharing companions across mismatched
     * filters means the AVG silently reads filtered data.
     */
    private static function filtersMatch(?FilterPredicate $a, ?FilterPredicate $b): bool
    {
        if (! $a instanceof FilterPredicate && ! $b instanceof FilterPredicate) {
            return true;
        }
        if (! $a instanceof FilterPredicate || ! $b instanceof FilterPredicate) {
            return false;
        }
        if ($a->getKind() !== $b->getKind()) {
            return false;
        }

        return match ($a->getKind()) {
            FilterPredicateKind::Equality => $a->getConditions() === $b->getConditions(),
            FilterPredicateKind::NotNull => $a->getNotNullColumn() === $b->getNotNullColumn(),
            FilterPredicateKind::Raw => self::normalizeRawSql($a->getRawSql())
                === self::normalizeRawSql($b->getRawSql()),
        };
    }

    /**
     * Normalises a raw SQL fragment for filter-equality comparison.
     * Lower-cases, collapses runs of whitespace, and trims. Lets
     * semantically-identical predicates with cosmetic differences
     * (`active = 1` vs `active=1` vs `ACTIVE = 1`) be treated as the
     * same filter — important because the registry uses
     * filter-equality to decide whether a user-declared Sum/Count is
     * a valid companion for an AVG. A mismatch silently routes AVG
     * through auto-promoted internal companions instead.
     *
     * Doesn't parse the SQL — comma/AND reorderings are still
     * considered different. The goal is to absorb whitespace/case
     * drift, not to be a full SQL equivalence engine.
     */
    private static function normalizeRawSql(?string $sql): ?string
    {
        if ($sql === null) {
            return null;
        }

        $trimmed = trim($sql);
        $collapsed = preg_replace('/\s+/', ' ', $trimmed);

        return strtolower($collapsed ?? $trimmed);
    }

    /**
     * @param  list<AggregateDefinitionContract>  $definitions
     * @param  class-string  $class
     */
    private static function assertNoDuplicateColumns(array $definitions, string $class): void
    {
        $seen = [];

        foreach ($definitions as $definition) {
            $column = $definition->getColumn();

            if (isset($seen[$column])) {
                throw new AggregateConfigurationException(sprintf(
                    '%s: aggregate column "%s" is declared more than once. '
                    .'Each stored aggregate column must be targeted by exactly one declaration.',
                    $class,
                    $column,
                ));
            }
            $seen[$column] = true;
        }
    }

    /**
     * Aggregate columns are derived state — every mutation overwrites
     * them via the maintenance machinery. Listing them in `$fillable`
     * means mass-assignment briefly writes user-supplied values that
     * the next save silently clobbers, producing apparent data loss
     * with no error trail.
     *
     * Catch this at registry-build time so models fail loudly during
     * boot rather than silently the first time someone passes a
     * request body through `Model::create($request->all())`.
     *
     * Excluded from the check:
     *  - Internal AVG companions: never user-declared, so users
     *    can't put them in `$fillable` by mistake. Skip to keep the
     *    error message focused on user-facing columns.
     *
     * @param  list<AggregateDefinitionContract>  $definitions
     * @param  class-string  $class
     */
    private static function assertNoAggregateColumnsInFillable(array $definitions, string $class): void
    {
        if ($definitions === []) {
            return;
        }

        // Eloquent stores `$fillable` as a protected property. Read
        // via reflection on the prototype — instantiating the model
        // for a property read is wasteful but matches the registry's
        // existing method-override pattern.
        try {
            $reflection = new ReflectionClass($class);
            if (! $reflection->hasProperty('fillable')) {
                return;
            }
            $prop = $reflection->getProperty('fillable');
            $instance = new $class;
            $value = $prop->getValue($instance);
            if (! is_array($value)) {
                return;
            }
            /** @var list<string> $fillable */
            $fillable = array_values(array_filter($value, is_string(...)));
        } catch (\ReflectionException) {
            return;
        }

        if ($fillable === []) {
            return;
        }

        $conflicts = [];
        foreach ($definitions as $definition) {
            if ($definition->isInternal()) {
                continue;
            }
            $column = $definition->getColumn();
            if (in_array($column, $fillable, true)) {
                $conflicts[] = $column;
            }
        }

        if ($conflicts === []) {
            return;
        }

        throw new AggregateConfigurationException(sprintf(
            '%s: aggregate column(s) [%s] appear in $fillable. Aggregate columns are derived state — '
            .'the package overwrites them on every mutation, so mass-assigning to them is silently undone. '
            .'Remove from $fillable; the column stays usable on the model (cast, hidden, etc. all still apply).',
            $class,
            implode(', ', $conflicts),
        ));
    }

    /**
     * Catches the modern Laravel idiom `protected $guarded = []` — which
     * makes every column mass-assignable. Combined with aggregate
     * columns, a request body containing those keys (e.g. a stale form
     * submission) gets silently clobbered on the next mutation: the
     * user's value persists for a tick, the maintenance hook overwrites
     * it, and there's no audit trail.
     *
     * `$fillable` users already get caught by
     * {@see self::assertNoAggregateColumnsInFillable()}. This check
     * covers the opposite end of the configuration: an explicit empty
     * `$guarded` (which Eloquent reads as "guard nothing"). The
     * default Eloquent guard list is `['*']` — i.e. guard everything —
     * which is safe and skipped.
     *
     * Resolution: list the aggregate columns in `$guarded`, or
     * switch to `$fillable`. The error message points the user at
     * both options.
     *
     * @param  list<AggregateDefinitionContract>  $definitions
     * @param  class-string  $class
     */
    private static function assertAggregateColumnsAreMassAssignmentSafe(array $definitions, string $class): void
    {
        if ($definitions === []) {
            return;
        }

        // `$fillable` and `$guarded` are inherited from Eloquent's
        // Model on every contract-satisfying class, so reflection on
        // them is straightforward. No defensive catches needed —
        // anything that breaks here is a precondition violation, not
        // a runtime variant.
        $reflection = new ReflectionClass($class);
        $instance = new $class;

        // `$fillable` non-empty? The user has already opted into the
        // allow-list model. The fillable check ahead of this method
        // has already confirmed aggregates are absent from it.
        $fillableValue = $reflection->getProperty('fillable')->getValue($instance);
        if (is_array($fillableValue) && $fillableValue !== []) {
            return;
        }

        $guardedValue = $reflection->getProperty('guarded')->getValue($instance);
        // `(array)` cast tolerates the rare user misconfiguration of
        // overriding $guarded with a non-array (Eloquent declares it
        // untyped, so PHP allows it). The cast normalises and falls
        // through to the same guard-membership check.
        /** @var list<string> $guarded */
        $guarded = array_values(array_filter((array) $guardedValue, is_string(...)));

        // Eloquent default — guard everything. Safe.
        if (in_array('*', $guarded, true)) {
            return;
        }

        // The user has either an empty guard (`[]` → guard nothing)
        // or a partial guard. For either to be safe with aggregates,
        // every user-facing aggregate column must be listed in
        // `$guarded`.
        $unguarded = [];
        foreach ($definitions as $definition) {
            if ($definition->isInternal()) {
                continue;
            }
            $column = $definition->getColumn();
            if (! in_array($column, $guarded, true)) {
                $unguarded[] = $column;
            }
        }

        if ($unguarded === []) {
            return;
        }

        throw new AggregateConfigurationException(sprintf(
            '%s: aggregate column(s) [%s] are mass-assignable (no $fillable, $guarded does not cover them). '
            .'Aggregate columns are derived state — the package overwrites them on every mutation, so '
            .'mass-assigning a stale value would be silently clobbered. Fix by either: '
            .'(1) adding them to $guarded, e.g. protected $guarded = [%s, ...]; or '
            .'(2) switching to an allow-list via $fillable that excludes them.',
            $class,
            implode(', ', $unguarded),
            implode(', ', array_map(static fn (string $c): string => "'{$c}'", $unguarded)),
        ));
    }
}
