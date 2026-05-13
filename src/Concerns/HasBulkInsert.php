<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Concerns;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Events\BulkInsertTreeCompleted;
use Vusys\NestedSet\Events\EventDispatcher;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\Query\TreeMutationBuilder;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * `bulkInsertTree`: seeds a nested input array under an optional anchor
 * (or as new roots) with one `makeGap` and one `fixAggregates` instead
 * of the O(N²) `appendToNode->save()` per-row pattern.
 *
 * Why this exists. Each call to `appendToNode($parent)->save()` issues
 * a CASE-WHEN UPDATE that shifts every row at or past the insertion
 * point — so seeding N rows one-by-one writes the gap-shift N times
 * over the same band of indexes. At N=1K on MySQL that path takes
 * ~15s; at N=10K, minutes.
 *
 * What this method keeps from the naive Eloquent loop. Every per-row
 * `creating` / `saving` / `created` / `saved` event still fires. The
 * model class's mutators, casts, mass-assignment rules, and
 * `$attributes`/`$casts` pipeline all run. Returned values are fully
 * hydrated model instances (id populated, `wasRecentlyCreated`
 * truthful). If you specifically need event-free, scriptable
 * insertion, wrap the call in `Model::withoutEvents(fn () => …)` —
 * that's the standard Laravel escape hatch and is the right level to
 * disable events at.
 *
 * Algorithm.
 *
 *   1. DFS-walk the input in memory, assigning relative
 *      lft/rgt/depth per node. No SQL yet.
 *
 *   2. Open *one* gap (`TreeMutationBuilder::makeGap`) at the anchor's
 *      rgt, size = 2 × node count. New roots skip this step entirely.
 *
 *   3. Per-row: construct the model with user attributes (respects
 *      `$fillable`), set the tree columns directly, `save()`. Eloquent
 *      events fire normally. Parent ids reference earlier-saved
 *      models in the same call (DFS pre-order guarantees parent-before-child).
 *
 *   4. Aggregate columns are kept consistent by wrapping the whole
 *      thing in {@see HasNestedSetAggregates::withDeferredAggregateMaintenance()} —
 *      per-row aggregate hooks no-op, one `fixAggregates($anchor)`
 *      fires once the closure exits.
 *
 * Scope. The method copies scope-column values from `$appendTo` to
 * every inserted row. Scoped models therefore *require* an anchor;
 * passing `null` on a scoped class throws.
 *
 * @mixin Model
 * @mixin HasNestedSet
 */
trait HasBulkInsert
{
    /**
     * @param  list<array<string, mixed>>  $tree
     * @return list<static>
     *
     * @throws ScopeViolationException When the model is scoped and `$appendTo` is null.
     * @throws InvalidArgumentException On malformed input or reserved-attribute use.
     */
    public static function bulkInsertTree(
        array $tree,
        ?HasNestedSet $appendTo = null,
    ): array {
        if ($tree === []) {
            return [];
        }

        // Scoped models need an anchor — the scope-column values are
        // copied from it onto every inserted row.
        $scopeColumns = NestedSetScopeResolver::columns(static::class);
        if ($scopeColumns !== [] && ! $appendTo instanceof HasNestedSet) {
            throw new ScopeViolationException(sprintf(
                '%s declares a scope (%s); pass an anchor node so bulkInsertTree can scope the inserted rows.',
                static::class,
                implode(', ', $scopeColumns),
            ));
        }

        if ($appendTo instanceof Model && ! $appendTo->exists) {
            throw new InvalidArgumentException(
                'bulkInsertTree: $appendTo must be a persisted model — its id and bounds are read.',
            );
        }

        $instance = new static;

        $lftCol = $instance->getLftName();
        $rgtCol = $instance->getRgtName();
        $depthCol = $instance->getDepthName();
        $parentIdCol = $instance->getParentIdName();
        $keyName = $instance->getKeyName();
        $reservedCols = [$lftCol, $rgtCol, $depthCol, $parentIdCol, $keyName];

        $scopeValues = $appendTo instanceof Model
            ? NestedSetScopeResolver::valuesFor($appendTo)
            : [];

        // 1. DFS walk → flat plan with relative lft/rgt/depth.
        $plan = self::bulkInsertPlan($tree, $reservedCols);

        $totalNodes = count($plan);
        $gapSize = 2 * $totalNodes;

        $anchorRgt = $appendTo instanceof HasNestedSet ? $appendTo->getRgt() : null;
        $anchorDepth = $appendTo instanceof HasNestedSet ? $appendTo->getDepth() : -1;
        $anchorParentId = null;
        if ($appendTo instanceof Model) {
            $anchorKey = $appendTo->getKey();
            $anchorParentId = is_numeric($anchorKey) ? (int) $anchorKey : null;
        }

        $connection = $instance->getConnection();
        $mutator = self::bulkInsertMutator($instance, $scopeValues);

        $startNs = hrtime(true);
        $saved = self::withDeferredAggregateMaintenance(
            fn (): array => $connection->transaction(static function () use (
                $plan,
                $anchorRgt,
                $anchorDepth,
                $anchorParentId,
                $gapSize,
                $instance,
                $rgtCol,
                $lftCol,
                $depthCol,
                $parentIdCol,
                $scopeValues,
                $mutator,
            ): array {
                if ($anchorRgt !== null) {
                    $mutator->makeGap($anchorRgt, $gapSize);
                    $boundsOffset = $anchorRgt - 1;
                } else {
                    // No anchor — seeding new roots. The new lft values
                    // start one past the current MAX(rgt) so we don't
                    // collide with any existing forest in the same table.
                    $rawMaxRgt = $instance->newQuery()->getQuery()->max($rgtCol);
                    $existingMaxRgt = is_numeric($rawMaxRgt) ? (int) $rawMaxRgt : 0;
                    $boundsOffset = $existingMaxRgt;
                }

                $saved = [];

                foreach ($plan as $node) {
                    $model = new static($node['attributes']);

                    foreach ($scopeValues as $col => $val) {
                        $model->setAttribute($col, $val);
                    }

                    if ($node['parentPlanIndex'] === null) {
                        $parentId = $anchorParentId;
                    } else {
                        $parentKey = $saved[$node['parentPlanIndex']]->getKey();
                        $parentId = is_numeric($parentKey) ? (int) $parentKey : null;
                    }

                    $model->setAttribute($lftCol, $node['lft'] + $boundsOffset);
                    $model->setAttribute($rgtCol, $node['rgt'] + $boundsOffset);
                    $model->setAttribute($depthCol, $node['depth'] + $anchorDepth + 1);
                    $model->setAttribute($parentIdCol, $parentId);

                    $model->save();

                    $saved[] = $model;
                }

                return $saved;
            }),
            anchor: $appendTo,
        );
        $durationMs = (hrtime(true) - $startNs) / 1_000_000;

        EventDispatcher::dispatch(new BulkInsertTreeCompleted(
            modelClass: static::class,
            anchorId: $anchorParentId,
            rowsInserted: count($saved),
            durationMs: $durationMs,
        ));

        return $saved;
    }

    /**
     * Walks the nested input depth-first and produces a flat list of
     * `{attributes, lft, rgt, depth, parentPlanIndex}` rows. The lft /
     * rgt values are relative (1..2N) — the caller shifts them by an
     * anchor offset.
     *
     * @param  list<array<string, mixed>>  $tree
     * @param  list<string>  $reservedCols
     * @return list<array{
     *     attributes: array<string, mixed>,
     *     lft: int,
     *     rgt: int,
     *     depth: int,
     *     parentPlanIndex: int|null,
     * }>
     */
    private static function bulkInsertPlan(array $tree, array $reservedCols): array
    {
        /** @var list<array{attributes: array<string, mixed>, lft: int, rgt: int, depth: int, parentPlanIndex: int|null}> $plan */
        $plan = [];
        $nextBound = 1;

        $walker = static function (
            array $branches,
            int $depth,
            ?int $parentPlanIndex,
        ) use (
            &$walker,
            &$plan,
            &$nextBound,
            $reservedCols,
        ): void {
            foreach ($branches as $branch) {
                if (! is_array($branch)) {
                    throw new InvalidArgumentException(
                        'bulkInsertTree: every node must be an associative array of attributes.',
                    );
                }

                /** @var array<string, mixed> $attrs */
                $attrs = $branch;
                $children = [];

                if (array_key_exists('children', $attrs)) {
                    $rawChildren = $attrs['children'];
                    unset($attrs['children']);
                    if (! is_array($rawChildren)) {
                        throw new InvalidArgumentException(
                            'bulkInsertTree: "children" must be an array of further nodes.',
                        );
                    }
                    /** @var list<array<string, mixed>> $children */
                    $children = array_values($rawChildren);
                }

                foreach ($reservedCols as $reserved) {
                    if (array_key_exists($reserved, $attrs)) {
                        throw new InvalidArgumentException(sprintf(
                            'bulkInsertTree: row attribute "%s" is reserved — the package computes nested-set columns and primary keys.',
                            $reserved,
                        ));
                    }
                }

                $thisIndex = count($plan);
                $lft = $nextBound++;
                // Reserve the slot up-front so any child entries get the
                // right `parentPlanIndex`; rgt is unknown until the
                // recursive call returns. We replace the whole entry
                // afterwards instead of poking just `rgt` so the array
                // shape stays trackable for static analysis.
                $plan[] = [
                    'attributes' => $attrs,
                    'lft' => $lft,
                    'rgt' => 0,
                    'depth' => $depth,
                    'parentPlanIndex' => $parentPlanIndex,
                ];

                $walker($children, $depth + 1, $thisIndex);

                $plan[$thisIndex] = [
                    'attributes' => $attrs,
                    'lft' => $lft,
                    'rgt' => $nextBound++,
                    'depth' => $depth,
                    'parentPlanIndex' => $parentPlanIndex,
                ];
            }
        };

        $walker($tree, 0, null);

        return $plan;
    }

    /**
     * Builds a {@see TreeMutationBuilder} pinned to the model's
     * connection / table / column names / scope. Used for the one-shot
     * `makeGap` at the start of `bulkInsertTree`.
     *
     * @param  array<string, mixed>  $scopeValues
     */
    private static function bulkInsertMutator(Model $instance, array $scopeValues): TreeMutationBuilder
    {
        /** @var Model&HasNestedSet $instance */
        return new TreeMutationBuilder(
            connection: $instance->getConnection(),
            table: $instance->getTable(),
            lft: $instance->getLftName(),
            rgt: $instance->getRgtName(),
            parentId: $instance->getParentIdName(),
            depth: $instance->getDepthName(),
            scope: $scopeValues,
        );
    }
}
