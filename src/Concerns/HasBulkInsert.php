<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Concerns;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Events\BulkInsert\BulkInsertNodeSaved;
use Vusys\NestedSet\Events\BulkInsert\BulkInsertTreeCompleted;
use Vusys\NestedSet\Events\BulkInsert\BulkInsertTreePlanned;
use Vusys\NestedSet\Events\BulkInsert\BulkInsertTreeSaved;
use Vusys\NestedSet\Events\BulkInsert\BulkInsertTreeStarting;
use Vusys\NestedSet\Events\Diagnostics\ScopeViolationDetected;
use Vusys\NestedSet\Events\EventDispatcher;
use Vusys\NestedSet\Exceptions\NestedSetInvalidArgumentException;
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
     * @param  bool  $forceFill  Bypass mass-assignment guards when hydrating
     *                           each row. Off by default so user-supplied
     *                           payloads still respect `$fillable`/`$guarded`;
     *                           internal deep-copy callers (cloneSubtreeTo)
     *                           pass true so guarded columns are copied
     *                           verbatim rather than silently zeroed.
     * @return list<static>
     *
     * @throws ScopeViolationException When the model is scoped and `$appendTo` is null.
     * @throws InvalidArgumentException On malformed input or reserved-attribute use.
     */
    public static function bulkInsertTree(
        array $tree,
        ?HasNestedSet $appendTo = null,
        bool $forceFill = false,
    ): array {
        if ($tree === []) {
            return [];
        }

        // The HasNestedSet contract intentionally doesn't require Model so
        // that unit-test stubs without a database can implement it, but
        // every real anchor passed at runtime is a Model — its primary key,
        // table, and persistence state are read below. Narrow once here so
        // the event payloads (which carry the anchor as `Model&HasNestedSet`)
        // see the correctly-typed value without per-site casts.
        if ($appendTo instanceof HasNestedSet && ! $appendTo instanceof Model) {
            throw new NestedSetInvalidArgumentException(sprintf(
                'bulkInsertTree: $appendTo must be a Model instance; got %s.',
                get_debug_type($appendTo),
            ));
        }

        EventDispatcher::dispatch(new BulkInsertTreeStarting(
            modelClass: static::class,
            appendTo: $appendTo,
            tree: $tree,
        ));

        // Scoped models need an anchor — the scope-column values are
        // copied from it onto every inserted row.
        $scopeColumns = NestedSetScopeResolver::columns(static::class);
        if ($scopeColumns !== [] && ! $appendTo instanceof HasNestedSet) {
            $message = sprintf(
                '%s declares a scope (%s); pass an anchor node so bulkInsertTree can scope the inserted rows.',
                static::class,
                implode(', ', $scopeColumns),
            );
            EventDispatcher::dispatch(new ScopeViolationDetected(
                modelClass: static::class,
                stage: 'bulk_insert',
                message: $message,
            ));
            throw new ScopeViolationException($message);
        }

        if ($appendTo instanceof HasNestedSet && ! $appendTo instanceof static) {
            throw new NestedSetInvalidArgumentException(sprintf(
                'bulkInsertTree: $appendTo must be an instance of %s, got %s. '
                .'A cross-class anchor would read bounds from the wrong table.',
                static::class,
                $appendTo::class,
            ));
        }

        if ($appendTo instanceof Model && ! $appendTo->exists) {
            throw new NestedSetInvalidArgumentException(
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

        EventDispatcher::dispatch(new BulkInsertTreePlanned(
            modelClass: static::class,
            appendTo: $appendTo,
            plan: $plan,
        ));

        $totalNodes = count($plan);
        $gapSize = 2 * $totalNodes;

        // The anchor's bounds/depth are read fresh inside the transaction
        // below — never from this possibly-stale in-memory instance. Only
        // its (immutable) key is captured here.
        $hasAnchor = $appendTo instanceof HasNestedSet;
        $anchorParentId = null;
        if ($appendTo instanceof Model) {
            $anchorKey = $appendTo->getKey();
            $anchorParentId = is_int($anchorKey) || is_string($anchorKey) ? $anchorKey : null;
        }

        $connection = $instance->getConnection();
        $mutator = self::bulkInsertMutator($instance, $scopeValues);

        // The post-bulk `fixAggregates($anchor)` treats $anchor as a
        // *subtree* boundary — only rows under $anchor are repaired.
        // Inserting under an interior or leaf node affects ancestors
        // too (their descendant counts and sums grow), so we widen the
        // repair anchor to the root of the tree containing $appendTo.
        $repairAnchor = self::resolveBulkInsertRepairAnchor($appendTo, $scopeValues);

        $startNs = hrtime(true);
        // Transaction OUTSIDE the deferral so the trailing fixAggregates pass
        // runs inside it: a crash (or a failed repair) between the inserts'
        // commit and the repair would otherwise leave persistent aggregate
        // drift. Inverting the nesting makes the inserts + the repair commit
        // (or roll back) atomically.
        $saved = $connection->transaction(fn (): array => self::withDeferredAggregateMaintenance(
            static function () use (
                $plan,
                $hasAnchor,
                $anchorParentId,
                $gapSize,
                $instance,
                $rgtCol,
                $lftCol,
                $depthCol,
                $parentIdCol,
                $scopeValues,
                $mutator,
                $appendTo,
                $forceFill,
            ): array {
                // Read + lock the anchor row inside the transaction — same
                // discipline as actAppendTo. The in-memory $appendTo may be
                // stale (a sibling deleted before it shifted its bounds
                // left), and the FOR UPDATE lock serialises concurrent
                // appenders against the same parent, which a bulk insert
                // otherwise never took.
                $anchorDepth = -1;
                if ($hasAnchor && $anchorParentId !== null) {
                    $anchorData = $mutator->getNodeData($anchorParentId, lockForUpdate: true);
                    $anchorDepth = $anchorData->depth;
                    $mutator->makeGap($anchorData->rgt, $gapSize);
                    $boundsOffset = $anchorData->rgt - 1;
                } else {
                    // No anchor — seeding new roots. The new lft values
                    // start one past the current MAX(rgt) so we don't
                    // collide with any existing forest in the same table.
                    $rawMaxRgt = $instance->newQuery()->getQuery()->max($rgtCol);
                    $existingMaxRgt = is_numeric($rawMaxRgt) ? (int) $rawMaxRgt : 0;
                    $boundsOffset = $existingMaxRgt;
                }

                $saved = [];
                $totalNodes = count($plan);

                foreach ($plan as $planIndex => $node) {
                    // forceFill bypasses $fillable/$guarded so internal
                    // deep-copy callers preserve guarded columns; the
                    // default path keeps mass-assignment protection for
                    // user-supplied payloads.
                    $model = $forceFill
                        ? (new static)->forceFill($node['attributes'])
                        : new static($node['attributes']);

                    foreach ($scopeValues as $col => $val) {
                        $model->setAttribute($col, $val);
                    }

                    if ($node['parentPlanIndex'] === null) {
                        $parentId = $anchorParentId;
                        $parentNode = $appendTo;
                    } else {
                        $parentModel = $saved[$node['parentPlanIndex']];
                        $parentKey = $parentModel->getKey();
                        $parentId = is_int($parentKey) || is_string($parentKey) ? $parentKey : null;
                        $parentNode = $parentModel;
                    }

                    $model->setAttribute($lftCol, $node['lft'] + $boundsOffset);
                    $model->setAttribute($rgtCol, $node['rgt'] + $boundsOffset);
                    $model->setAttribute($depthCol, $node['depth'] + $anchorDepth + 1);
                    $model->setAttribute($parentIdCol, $parentId);

                    $model->save();

                    $saved[] = $model;

                    EventDispatcher::dispatch(new BulkInsertNodeSaved(
                        modelClass: static::class,
                        node: $model,
                        planIndex: $planIndex,
                        totalNodes: $totalNodes,
                        parent: $parentNode,
                    ));
                }

                return $saved;
            },
            anchor: $repairAnchor,
        ));
        $durationMs = (hrtime(true) - $startNs) / 1_000_000;

        EventDispatcher::dispatch(new BulkInsertTreeSaved(
            modelClass: static::class,
            anchorId: $anchorParentId,
            appendTo: $appendTo,
            nodes: $saved,
        ));

        $nodeIds = [];
        foreach ($saved as $savedNode) {
            $key = $savedNode->getKey();
            if (is_int($key) || is_string($key)) {
                $nodeIds[] = $key;
            }
        }

        EventDispatcher::dispatch(new BulkInsertTreeCompleted(
            modelClass: static::class,
            anchorId: $anchorParentId,
            rowsInserted: count($saved),
            durationMs: $durationMs,
            nodeIds: $nodeIds,
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

        // Iterative DFS using a task stack — keeps deep input trees
        // (thousands of levels) from blowing PHP's call stack. The
        // task types:
        //   - 'enter': allocate lft for this branch, push children
        //   - 'exit':  allocate rgt for an already-entered branch
        //
        // Tasks are LIFO so children of a node pop in order if pushed
        // in reverse. Each `enter` queues its `exit` task BEFORE its
        // children so that exit runs after every descendant.
        /** @var list<array{type: 'enter', branch: mixed, depth: int, parentPlanIndex: int|null}|array{type: 'exit', planIndex: int}> $tasks */
        $tasks = [];
        foreach (array_reverse($tree) as $branch) {
            $tasks[] = [
                'type' => 'enter',
                'branch' => $branch,
                'depth' => 0,
                'parentPlanIndex' => null,
            ];
        }

        while ($tasks !== []) {
            $task = array_pop($tasks);

            if ($task['type'] === 'exit') {
                $entry = $plan[$task['planIndex']];
                $entry['rgt'] = $nextBound++;
                $plan[$task['planIndex']] = $entry;

                continue;
            }

            $branch = $task['branch'];
            if (! is_array($branch)) {
                throw new NestedSetInvalidArgumentException(
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
                    throw new NestedSetInvalidArgumentException(
                        'bulkInsertTree: "children" must be an array of further nodes.',
                    );
                }
                /** @var list<array<string, mixed>> $children */
                $children = array_values($rawChildren);
            }

            foreach ($reservedCols as $reserved) {
                if (array_key_exists($reserved, $attrs)) {
                    throw new NestedSetInvalidArgumentException(sprintf(
                        'bulkInsertTree: row attribute "%s" is reserved — the package computes nested-set columns and primary keys.',
                        $reserved,
                    ));
                }
            }

            $thisIndex = count($plan);
            $plan[] = [
                'attributes' => $attrs,
                'lft' => $nextBound++,
                'rgt' => 0,
                'depth' => $task['depth'],
                'parentPlanIndex' => $task['parentPlanIndex'],
            ];

            // Queue the exit task BEFORE the children so it pops
            // after every descendant has been processed.
            $tasks[] = ['type' => 'exit', 'planIndex' => $thisIndex];

            // Push children in reverse so the first child pops first.
            foreach (array_reverse($children) as $child) {
                $tasks[] = [
                    'type' => 'enter',
                    'branch' => $child,
                    'depth' => $task['depth'] + 1,
                    'parentPlanIndex' => $thisIndex,
                ];
            }
        }

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
            idCol: $instance->getKeyName(),
        );
    }

    /**
     * Returns the anchor the post-bulk `fixAggregates` pass should use.
     * When the user's `$appendTo` is a root (or null), it's already
     * the correct boundary. When it's an interior or leaf node, the
     * tree's actual root is loaded and returned so the repair pass
     * walks every ancestor whose aggregates the insert disturbed.
     *
     * @param  array<string, mixed>  $scopeValues
     */
    private static function resolveBulkInsertRepairAnchor(
        ?HasNestedSet $appendTo,
        array $scopeValues,
    ): ?HasNestedSet {
        if (! $appendTo instanceof Model) {
            return null;
        }

        if ($appendTo->getParentId() === null) {
            return $appendTo;
        }

        $instance = new static;

        $query = $instance->newQuery()
            ->where($instance->getLftName(), '<=', $appendTo->getLft())
            ->where($instance->getRgtName(), '>=', $appendTo->getRgt())
            ->whereNull($instance->getParentIdName());

        foreach ($scopeValues as $col => $value) {
            $query->where($col, $value);
        }

        $root = $query->first();

        if ($root instanceof HasNestedSet) {
            return $root;
        }

        return $appendTo;
    }
}
