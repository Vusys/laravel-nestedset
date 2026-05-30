<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Walker;

use Closure;
use Countable;
use Generator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Vusys\NestedSet\Contracts\HasNestedSet;

/**
 * Pure in-memory traversal helper over an already-loaded subtree.
 *
 * Builds two indexes on construction (`O(N)` time, `O(N)` memory):
 *
 *  - `byKey: key => Model` — fast node lookup.
 *  - `childrenByParentKey: parentKey => list<key>` — children list per
 *    parent, sorted by `lft` (defensively — the canonical input is
 *    already lft-ordered).
 *
 * Generators yield Model instances. The visitor-form `walk()` adds
 * signal handling (skip subtree / stop) on top of the same iteration.
 *
 * The walker never issues a database query. Pass a loaded collection;
 * for callers that supply only the root, the empty case still produces
 * exactly one visit (the root itself).
 *
 * @phpstan-type NodeKey int|string
 */
final class SubtreeWalker implements Countable
{
    /** @var array<int|string, Model&HasNestedSet> */
    private array $byKey = [];

    /** @var array<int|string, list<int|string>> */
    private array $childrenByParentKey = [];

    private readonly int|string $rootKey;

    /** Number of reachable nodes from the walk root. Memoised on first count() call. */
    private ?int $reachableCount = null;

    /** Deepest depth reached relative to the walk root. Memoised. */
    private ?int $maxDepthCache = null;

    /** Number of reachable leaves. Memoised. */
    private ?int $leafCountCache = null;

    /**
     * @param  iterable<Model&HasNestedSet>  $nodes  Flat collection of
     *                                               nodes that make up
     *                                               the loaded subtree.
     *                                               Order does not matter
     *                                               — children are
     *                                               sorted by `lft`
     *                                               on construction.
     * @param  Model&HasNestedSet  $root  Walk root. Included in the index
     *                                    even when absent from `$nodes`
     *                                    (e.g. caller passed only the
     *                                    descendants relation).
     */
    public function __construct(iterable $nodes, Model&HasNestedSet $root)
    {
        $rootKey = $this->keyOf($root);
        $this->rootKey = $rootKey;

        foreach ($nodes as $node) {
            $this->byKey[$this->keyOf($node)] = $node;
        }

        // Caller may have loaded only descendants; the root anchor still
        // needs to exist in the index for the walk to start.
        if (! isset($this->byKey[$rootKey])) {
            $this->byKey[$rootKey] = $root;
        }

        $this->buildChildrenIndex();
    }

    /**
     * Depth-first pre-order: root, then each child's subtree in lft order.
     *
     * @return Generator<int, Model&HasNestedSet>
     */
    public function dfs(?WalkFilter $filter = null): Generator
    {
        foreach ($this->iteratePreOrder($filter) as [$node, $_ctx]) {
            yield $node;
        }
    }

    /**
     * Depth-first post-order: children-first then parent. Filters apply
     * to visit only — `SkipSubtree` is meaningless here, since by the
     * time the parent is visited, its children already have been.
     *
     * @return Generator<int, Model&HasNestedSet>
     */
    public function dfsPostOrder(?WalkFilter $filter = null): Generator
    {
        foreach ($this->iteratePostOrder($filter) as [$node, $_ctx]) {
            yield $node;
        }
    }

    /**
     * Breadth-first: depth 0, then depth 1, etc.
     *
     * @return Generator<int, Model&HasNestedSet>
     */
    public function bfs(?WalkFilter $filter = null): Generator
    {
        foreach ($this->iterateBfs($filter) as [$node, $_ctx]) {
            yield $node;
        }
    }

    /**
     * Visitor-driven walk. The visitor receives the node and a
     * {@see WalkContext} carrying depth / parent / sibling info.
     * Returning `WalkSignal::SkipSubtree` from a pre-order or BFS visit
     * skips descent into the node's children; `WalkSignal::Stop` halts
     * immediately. `null` (or no return — i.e. a `void` visitor) continues.
     *
     * The visitor return type is declared as `mixed` rather than the
     * tighter `WalkSignal|null` so a `: void` visitor (the common shape
     * for side-effecting passes) typechecks cleanly without an explicit
     * `return null;`. Anything other than the two `WalkSignal` cases is
     * treated as the continue signal.
     *
     * @param  Closure(Model&HasNestedSet, WalkContext): mixed  $visitor
     * @param  'pre'|'post'|'bfs'  $strategy
     */
    public function walk(Closure $visitor, string $strategy = 'pre', ?WalkFilter $filter = null): void
    {
        // Guard before the match so a caller bypassing the phpdoc union
        // (e.g. a string from config) gets an actionable error instead
        // of PHP's bare UnhandledMatchError.
        self::assertStrategy($strategy);

        $tuples = match ($strategy) {
            'pre' => $this->iteratePreOrder($filter, signalAware: true),
            'post' => $this->iteratePostOrder($filter),
            'bfs' => $this->iterateBfs($filter, signalAware: true),
        };

        // Drive the generator manually instead of foreach because we
        // need to choose between `send()` (which itself advances) and
        // `next()` per iteration based on the visitor's signal.
        while ($tuples->valid()) {
            $tuple = $tuples->current();
            [$node, $ctx] = $tuple;
            $signal = $visitor($node, $ctx);

            if ($signal === WalkSignal::Stop) {
                return;
            }

            if ($signal === WalkSignal::SkipSubtree && $strategy !== 'post') {
                $tuples->send(WalkSignal::SkipSubtree);

                continue;
            }

            $tuples->next();
        }
    }

    /**
     * Returns the loaded subtree flattened to a Collection in the chosen
     * strategy's order. Composition of `walk()` + collect; useful when
     * the caller wants an iterable rather than a callback shape.
     *
     * @param  'pre'|'post'|'bfs'  $strategy
     * @return EloquentCollection<int, Model&HasNestedSet>
     */
    public function flatten(string $strategy = 'pre', ?WalkFilter $filter = null): EloquentCollection
    {
        self::assertStrategy($strategy);

        $generator = match ($strategy) {
            'pre' => $this->dfs($filter),
            'post' => $this->dfsPostOrder($filter),
            'bfs' => $this->bfs($filter),
        };

        /** @var list<Model&HasNestedSet> $items */
        $items = [];
        foreach ($generator as $node) {
            $items[] = $node;
        }

        return new EloquentCollection($items);
    }

    /**
     * Count of nodes reachable from the walk root via parent_id pointers
     * present in the loaded subtree. Orphans (rows whose parent isn't in
     * the collection and aren't the walk root) are excluded — that's the
     * difference between this count and `$loadedCollection->count()`.
     */
    public function count(): int
    {
        $count = $this->reachableCount ??= $this->computeCounts()['count'];

        return max(0, $count);
    }

    /**
     * Deepest depth reached relative to the walk root. A root-only walk
     * has `maxDepth() === 0`.
     */
    public function maxDepth(): int
    {
        return $this->maxDepthCache ??= $this->computeCounts()['maxDepth'];
    }

    /**
     * Number of reachable leaves — nodes whose subtree (within the
     * loaded collection) has no children.
     */
    public function leafCount(): int
    {
        return $this->leafCountCache ??= $this->computeCounts()['leaves'];
    }

    /**
     * Walks from `$node` up via parent_id pointers, collecting the
     * ancestor chain. Stops before the walk root and before any node
     * that isn't in the loaded subtree. Used by {@see WalkContext::pathToRoot()}.
     *
     * @return list<Model&HasNestedSet>
     */
    public function pathToRootFrom(Model&HasNestedSet $node): array
    {
        /** @var list<Model&HasNestedSet> $path */
        $path = [];

        $currentKey = $this->keyOf($node);
        if ($currentKey === $this->rootKey) {
            return $path;
        }

        $current = $this->byKey[$currentKey] ?? null;
        $guard = count($this->byKey) + 1;

        while ($current !== null && $guard-- > 0) {
            $parentId = $current->getParentId();
            if ($parentId === null || $parentId === $this->rootKey || ! isset($this->byKey[$parentId])) {
                break;
            }
            $path[] = $this->byKey[$parentId];
            $current = $this->byKey[$parentId];
        }

        return $path;
    }

    /**
     * Pre-order iteration with explicit stack so deep trees do not blow
     * PHP's call stack. Yields `[Model, WalkContext]` tuples.
     *
     * When `$signalAware` is true, the caller can `Generator::send()`
     * `WalkSignal::SkipSubtree` to skip descent into the just-yielded
     * node. Used by {@see walk()}; the public {@see dfs()} generator
     * discards the second tuple slot.
     *
     * @return Generator<int, array{0: Model&HasNestedSet, 1: WalkContext}, WalkSignal|null, void>
     */
    private function iteratePreOrder(?WalkFilter $filter, bool $signalAware = false): Generator
    {
        // Each frame: [key, depth, parentModel|null, siblingIndex, siblingCount].
        /** @var list<array{0: int|string, 1: int, 2: (Model&HasNestedSet)|null, 3: int, 4: int}> $stack */
        $stack = [[$this->rootKey, 0, null, 0, 1]];

        while ($stack !== []) {
            $frame = array_pop($stack);
            [$key, $depth, $parent, $idx, $count] = $frame;

            if (! isset($this->byKey[$key])) {
                continue;
            }
            $node = $this->byKey[$key];

            $ctx = new WalkContext(
                depth: $depth,
                parent: $parent,
                siblingIndex: $idx,
                siblingCount: $count,
                walker: $this,
                node: $node,
            );

            $isRoot = $key === $this->rootKey;
            $allowed = ! $filter instanceof WalkFilter || $filter->allows($node, $ctx);
            $skipVisit = ! $allowed || ($isRoot && $filter instanceof WalkFilter && ! $filter->includeRoot);
            $skipDescent = ! $allowed;

            if (! $skipVisit) {
                $signal = yield [$node, $ctx];
                if ($signalAware && $signal === WalkSignal::SkipSubtree) {
                    continue;
                }
            }

            if ($skipDescent) {
                continue;
            }

            $children = $this->childrenByParentKey[$key] ?? [];
            $childCount = count($children);
            // Push in reverse so the first child pops first.
            for ($i = $childCount - 1; $i >= 0; $i--) {
                $stack[] = [$children[$i], $depth + 1, $node, $i, $childCount];
            }
        }
    }

    /**
     * Post-order iteration: children before parent. Each node visits
     * exactly once, after its subtree has finished.
     *
     * @return Generator<int, array{0: Model&HasNestedSet, 1: WalkContext}, mixed, void>
     */
    private function iteratePostOrder(?WalkFilter $filter): Generator
    {
        // 'enter' frames push the exit-frame and children; 'exit' frames yield.
        /** @var list<array{phase: 'enter'|'exit', key: int|string, depth: int, parent: (Model&HasNestedSet)|null, idx: int, count: int}> $stack */
        $stack = [[
            'phase' => 'enter',
            'key' => $this->rootKey,
            'depth' => 0,
            'parent' => null,
            'idx' => 0,
            'count' => 1,
        ]];

        while ($stack !== []) {
            $frame = array_pop($stack);
            if (! isset($this->byKey[$frame['key']])) {
                continue;
            }
            $node = $this->byKey[$frame['key']];
            $ctx = new WalkContext(
                depth: $frame['depth'],
                parent: $frame['parent'],
                siblingIndex: $frame['idx'],
                siblingCount: $frame['count'],
                walker: $this,
                node: $node,
            );

            $isRoot = $frame['key'] === $this->rootKey;
            $allowed = ! $filter instanceof WalkFilter || $filter->allows($node, $ctx);

            if ($frame['phase'] === 'exit') {
                $skipVisit = ! $allowed || ($isRoot && $filter instanceof WalkFilter && ! $filter->includeRoot);
                if (! $skipVisit) {
                    yield [$node, $ctx];
                }

                continue;
            }

            // 'enter' phase
            if (! $allowed) {
                continue;
            }

            $stack[] = [
                'phase' => 'exit',
                'key' => $frame['key'],
                'depth' => $frame['depth'],
                'parent' => $frame['parent'],
                'idx' => $frame['idx'],
                'count' => $frame['count'],
            ];

            $children = $this->childrenByParentKey[$frame['key']] ?? [];
            $childCount = count($children);
            for ($i = $childCount - 1; $i >= 0; $i--) {
                $stack[] = [
                    'phase' => 'enter',
                    'key' => $children[$i],
                    'depth' => $frame['depth'] + 1,
                    'parent' => $node,
                    'idx' => $i,
                    'count' => $childCount,
                ];
            }
        }
    }

    /**
     * Breadth-first iteration: queue-driven, depth 0 then 1 etc.
     *
     * @return Generator<int, array{0: Model&HasNestedSet, 1: WalkContext}, WalkSignal|null, void>
     */
    private function iterateBfs(?WalkFilter $filter, bool $signalAware = false): Generator
    {
        /** @var list<array{0: int|string, 1: int, 2: (Model&HasNestedSet)|null, 3: int, 4: int}> $queue */
        $queue = [[$this->rootKey, 0, null, 0, 1]];
        $head = 0;

        while ($head < count($queue)) {
            $frame = $queue[$head++];
            [$key, $depth, $parent, $idx, $count] = $frame;

            if (! isset($this->byKey[$key])) {
                continue;
            }
            $node = $this->byKey[$key];
            $ctx = new WalkContext(
                depth: $depth,
                parent: $parent,
                siblingIndex: $idx,
                siblingCount: $count,
                walker: $this,
                node: $node,
            );

            $isRoot = $key === $this->rootKey;
            $allowed = ! $filter instanceof WalkFilter || $filter->allows($node, $ctx);
            $skipVisit = ! $allowed || ($isRoot && $filter instanceof WalkFilter && ! $filter->includeRoot);
            $skipDescent = ! $allowed;

            if (! $skipVisit) {
                $signal = yield [$node, $ctx];
                if ($signalAware && $signal === WalkSignal::SkipSubtree) {
                    continue;
                }
            }

            if ($skipDescent) {
                continue;
            }

            $children = $this->childrenByParentKey[$key] ?? [];
            $childCount = count($children);
            for ($i = 0; $i < $childCount; $i++) {
                $queue[] = [$children[$i], $depth + 1, $node, $i, $childCount];
            }
        }
    }

    /**
     * Builds `childrenByParentKey` from the loaded collection. Children
     * are sorted by `lft` so callers passing reordered collections still
     * get a deterministic walk (the canonical lft-ordered input keeps
     * this sort a no-op).
     */
    private function buildChildrenIndex(): void
    {
        /** @var array<int|string, list<array{key: int|string, lft: int}>> $unsorted */
        $unsorted = [];

        foreach ($this->byKey as $key => $node) {
            $parentId = $node->getParentId();
            if ($parentId === null) {
                // Roots or orphans — no parent entry to attach to. The
                // walk-root case is handled separately; orphans never
                // get visited.
                continue;
            }
            if (! isset($this->byKey[$parentId])) {
                // Roots or orphans — no parent entry to attach to. The
                // walk-root case is handled separately; orphans never
                // get visited.
                continue;
            }
            $unsorted[$parentId][] = ['key' => $key, 'lft' => $node->getLft()];
        }

        foreach ($unsorted as $parentId => $entries) {
            usort($entries, static fn (array $a, array $b): int => $a['lft'] <=> $b['lft']);
            $this->childrenByParentKey[$parentId] = array_map(
                static fn (array $entry): int|string => $entry['key'],
                $entries,
            );
        }
    }

    /**
     * Single-pass BFS that computes the reachable count, deepest depth,
     * and leaf count together — all three are O(N) and called rarely
     * enough to share the work.
     *
     * @return array{count: int, maxDepth: int, leaves: int}
     */
    private function computeCounts(): array
    {
        $count = 0;
        $maxDepth = 0;
        $leaves = 0;

        /** @var list<array{0: int|string, 1: int}> $queue */
        $queue = [[$this->rootKey, 0]];
        $head = 0;

        while ($head < count($queue)) {
            [$key, $depth] = $queue[$head++];
            if (! isset($this->byKey[$key])) {
                continue;
            }
            $count++;
            if ($depth > $maxDepth) {
                $maxDepth = $depth;
            }
            $children = $this->childrenByParentKey[$key] ?? [];
            if ($children === []) {
                $leaves++;

                continue;
            }
            foreach ($children as $childKey) {
                $queue[] = [$childKey, $depth + 1];
            }
        }

        return ['count' => $count, 'maxDepth' => $maxDepth, 'leaves' => $leaves];
    }

    /**
     * Verifies `$strategy` is one of the three supported labels and
     * throws a clearer error than PHP's bare `UnhandledMatchError` when
     * it isn't. The phpdoc on `walk()` / `flatten()` already narrows
     * the type for static analysis; this guard handles the runtime
     * case where a caller bypasses it (e.g. a string from config).
     */
    private static function assertStrategy(string $strategy): void
    {
        if ($strategy !== 'pre' && $strategy !== 'post' && $strategy !== 'bfs') {
            throw new InvalidArgumentException(sprintf(
                'Unsupported walk strategy: "%s"; expected one of: pre, post, bfs.',
                $strategy,
            ));
        }
    }

    /**
     * Narrows Model::getKey() to int|string — nested-set models all use
     * scalar primary keys, so the cast is safe and removes a `mixed`
     * from every caller.
     */
    private function keyOf(Model&HasNestedSet $node): int|string
    {
        $key = $node->getKey();
        if (is_int($key) || is_string($key)) {
            return $key;
        }

        throw new InvalidArgumentException(sprintf(
            'SubtreeWalker requires scalar primary keys; got %s on %s.',
            get_debug_type($key),
            $node::class,
        ));
    }
}
