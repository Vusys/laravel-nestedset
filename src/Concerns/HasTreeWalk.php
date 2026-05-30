<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Concerns;

use Closure;
use Generator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\UnloadedSubtreeException;
use Vusys\NestedSet\Walker\SubtreeWalker;
use Vusys\NestedSet\Walker\WalkContext;
use Vusys\NestedSet\Walker\WalkFilter;
use Vusys\NestedSet\Walker\WalkSignal;

/**
 * User-facing in-memory traversal helpers — DFS pre/post, BFS, a
 * visitor-form `walk()`, and a `flattenedSubtree()` convenience.
 *
 * Source data discipline. The walker never queries the database. When
 * `$subtree` is omitted, the method falls back to `$this->descendants`
 * (which must be relation-loaded). With neither available, it throws
 * {@see UnloadedSubtreeException} pointing the caller at the two
 * supported load paths — `->load('descendants')` or passing a
 * collection explicitly.
 *
 * Why the strictness. If a caller has deliberately narrowed the loaded
 * subtree (a filtered eager-load, a where-clause, a depth-limited get),
 * silently re-fetching the full descendants would surprise-widen the
 * scope. The exception forces the load decision to be visible at the
 * call site.
 *
 * @mixin Model
 * @mixin HasNestedSet
 */
trait HasTreeWalk
{
    /**
     * Depth-first pre-order: root, then each child subtree in lft
     * order. Yields {@see Model} instances.
     *
     * @param  iterable<Model&HasNestedSet>|null  $subtree
     * @return Generator<int, Model&HasNestedSet>
     */
    public function dfs(?iterable $subtree = null, ?WalkFilter $filter = null): Generator
    {
        return $this->resolveWalker($subtree)->dfs($filter);
    }

    /**
     * Depth-first post-order: children first, then parent.
     *
     * @param  iterable<Model&HasNestedSet>|null  $subtree
     * @return Generator<int, Model&HasNestedSet>
     */
    public function dfsPostOrder(?iterable $subtree = null, ?WalkFilter $filter = null): Generator
    {
        return $this->resolveWalker($subtree)->dfsPostOrder($filter);
    }

    /**
     * Breadth-first: depth 0, then depth 1, etc.
     *
     * @param  iterable<Model&HasNestedSet>|null  $subtree
     * @return Generator<int, Model&HasNestedSet>
     */
    public function bfs(?iterable $subtree = null, ?WalkFilter $filter = null): Generator
    {
        return $this->resolveWalker($subtree)->bfs($filter);
    }

    /**
     * Visitor-form walk. The visitor receives the node and a
     * {@see WalkContext} carrying depth / parent / sibling info; it may
     * return a {@see WalkSignal} to skip descent (`SkipSubtree`, pre /
     * BFS only) or halt entirely (`Stop`).
     *
     * @param  Closure(Model&HasNestedSet, WalkContext): mixed  $visitor
     * @param  'pre'|'post'|'bfs'  $strategy
     * @param  iterable<Model&HasNestedSet>|null  $subtree
     */
    public function walk(
        Closure $visitor,
        string $strategy = 'pre',
        ?iterable $subtree = null,
        ?WalkFilter $filter = null,
    ): void {
        $this->resolveWalker($subtree)->walk($visitor, $strategy, $filter);
    }

    /**
     * Returns the loaded subtree as a Collection in the chosen order.
     *
     * Use this when the caller wants an iterable rather than a callback.
     * Order matches `dfs()` / `dfsPostOrder()` / `bfs()` for the same
     * strategy argument.
     *
     * @param  'pre'|'post'|'bfs'  $strategy
     * @param  iterable<Model&HasNestedSet>|null  $subtree
     * @return EloquentCollection<int, Model&HasNestedSet>
     */
    public function flattenedSubtree(
        string $strategy = 'pre',
        ?iterable $subtree = null,
        ?WalkFilter $filter = null,
    ): EloquentCollection {
        return $this->resolveWalker($subtree)->flatten($strategy, $filter);
    }

    /**
     * Returns a walker for `$this` over the supplied subtree, or over
     * the already-loaded `descendants` relation if `$subtree` is null.
     *
     * Accepts `iterable` rather than the concrete EloquentCollection so
     * callers can hand in a model-specific collection
     * (`Collection<int, Category>`) without falling foul of the
     * collection generic's invariance. The walker reads the iterable
     * exactly once at construction time.
     *
     * @param  iterable<Model&HasNestedSet>|null  $subtree
     */
    private function resolveWalker(?iterable $subtree): SubtreeWalker
    {
        if ($subtree !== null) {
            return new SubtreeWalker($subtree, $this);
        }

        if ($this->relationLoaded('descendants')) {
            /** @var iterable<Model&HasNestedSet> $loaded */
            $loaded = $this->getRelation('descendants');

            return new SubtreeWalker($loaded, $this);
        }

        throw new UnloadedSubtreeException(sprintf(
            "%s::%s: the walker is in-memory only — call ->load('descendants') first "
            .'or pass a collection explicitly ($subtree argument). The walker does not '
            .'query the database.',
            static::class,
            debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? 'walk',
        ));
    }
}
