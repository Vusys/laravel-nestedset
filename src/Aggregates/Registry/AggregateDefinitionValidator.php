<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Registry;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Definitions\CompanionSourceOrigin;
use Vusys\NestedSet\Aggregates\Definitions\CompanionSourceTransform;
use Vusys\NestedSet\Aggregates\Definitions\CompanionSpec;
use Vusys\NestedSet\Aggregates\Definitions\ListenerAggregateDefinition;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicate;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicateKind;
use Vusys\NestedSet\Contracts\AggregateDefinitionContract;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;

/**
 * Companion auto-promotion + structural validation for the parsed
 * definition list. Owns the "AVG → Sum + Count", "Variance → Sum +
 * SumSq + Count", "WeightedAvg → Σwv + Σw", "BoolOr/BoolAnd → Sum +
 * Count", "GeoMean/HarmonicMean → SumLn|SumRecip + Count" promotions,
 * plus the assertions that catch user misconfiguration at boot time
 * (duplicate columns, aggregate columns in `$fillable`, aggregate
 * columns mass-assignable via `$guarded = []`).
 *
 * {@see AggregateRegistry::for()} runs this after
 * {@see AggregateAttributeParser::parse()} so the cached list contains
 * the full expanded set the maintenance machinery expects.
 *
 * Companion-matching helpers ({@see filtersMatch()},
 * {@see indexBySource()}, {@see indexListenersByClassAndInclusive()},
 * {@see normalizeRawSql()}) are intentionally public because
 * {@see AggregateRegistry}'s per-function companion-lookup helpers
 * (`avgCompanionsFor`, `varianceCompanionsFor`, etc.) reuse the same
 * matching rules. Keeping one implementation prevents the lookup and
 * promotion paths from drifting against each other.
 */
final class AggregateDefinitionValidator
{
    /**
     * Expands `$definitions` with auto-promoted companions and asserts
     * the result is internally consistent + mass-assignment-safe on
     * `$class`. Returns the expanded list.
     *
     * @param  class-string<Model&HasNestedSet>  $class
     * @param  list<AggregateDefinitionContract>  $definitions
     * @return list<AggregateDefinitionContract>
     */
    public static function promoteAndValidate(string $class, array $definitions): array
    {
        $definitions = self::autoPromoteCompanions($definitions);

        self::assertNoDuplicateColumns($definitions, $class);
        self::assertNoAggregateColumnsInFillable($definitions, $class);
        self::assertAggregateColumnsAreMassAssignmentSafe($definitions, $class);

        return $definitions;
    }

    /**
     * True when two filter predicates would select the same rows. Used
     * to decide whether a user-declared Sum / Count is a semantically-
     * correct companion for an AVG. Sharing companions across mismatched
     * filters means the AVG silently reads filtered data.
     */
    public static function filtersMatch(?FilterPredicate $a, ?FilterPredicate $b): bool
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
    public static function normalizeRawSql(?string $sql): ?string
    {
        if ($sql === null) {
            return null;
        }

        $trimmed = trim($sql);
        $collapsed = preg_replace('/\s+/', ' ', $trimmed);

        return strtolower($collapsed ?? $trimmed);
    }

    /**
     * Index SQL aggregate definitions by source column name. Definitions
     * without a source (e.g. plain `COUNT(*)`) are dropped — they have
     * no candidate companions to match.
     *
     * @param  list<AggregateDefinitionContract>  $definitions
     * @return array<string, list<AggregateDefinition>>
     */
    public static function indexBySource(array $definitions): array
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
     * Group listener definitions by `listenerClass|inclusive` so AVG
     * auto-promotion can detect already-declared Sum/Count companions
     * (a user might declare Sum + Count + Avg manually for the same
     * listener; we skip promotion in that case).
     *
     * @param  list<AggregateDefinitionContract>  $definitions
     * @return array<string, list<ListenerAggregateDefinition>>
     */
    public static function indexListenersByClassAndInclusive(array $definitions): array
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
     * For each aggregate definition that declares a non-empty
     * {@see AggregateFunction::companionSet()},
     * adds the missing companion definitions as `internal: true`.
     * Companions inherit the parent's source, inclusivity, and filter
     * predicate so they stay semantically aligned with the user-facing
     * aggregate they support.
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
                        // would feed it a different row set. Lazy
                        // candidates are excluded too — their stored
                        // value is NULL between mutations, and the
                        // parent display column would inherit that.
                        $companionsForSource = array_values(array_filter(
                            $companionsForSource,
                            static fn (AggregateDefinition $candidate): bool => self::filtersMatch(
                                $candidate->filter,
                                $definition->filter,
                            ) && $candidate->inclusive === $definition->inclusive
                                && $candidate->sourceTransform === CompanionSourceTransform::Identity
                                && ! $candidate->lazy,
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
                    // Variance / geomean / harmonic spawn multiple Sum-family
                    // companions distinguished only by source transform
                    // (Sum/Identity vs Sum/Square vs Sum/Ln vs Sum/Recip), and
                    // by parent filter. A plain user Sum on the same listener
                    // must not be adopted as a Sum_sq companion. Match the
                    // full triple (operation, transform, filter) plus
                    // non-lazy (lazy companions stay NULL between mutations
                    // and would leak that NULL into the parent display).
                    $alreadyDeclared = false;
                    foreach ($companions as $companion) {
                        if ($companion->lazy) {
                            continue;
                        }
                        if ($companion->operation === $spec->function
                            && $companion->sourceTransform === $spec->sourceTransform
                            && self::filtersMatch($companion->filter, $definition->filter)
                        ) {
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
                        filter: $definition->filter,
                        sourceTransform: $spec->sourceTransform,
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
