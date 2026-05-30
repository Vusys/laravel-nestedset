<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Walker;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\HasNestedSet;

/**
 * Static pruning rules for {@see SubtreeWalker} walks.
 *
 * A filter prunes both visit and descent: when a node fails the filter,
 * the walker neither yields it nor walks its subtree. Composes with
 * `WalkFilter::compose($a, $b)` (logical AND of predicates; minimum of
 * the two `maxDepth` values).
 *
 * Named constructors cover the two common shapes — `depth(int)` for a
 * depth ceiling and `where(Closure)` for predicate-based pruning. The
 * raw constructor stays available for callers that want to set all
 * three fields together.
 *
 * @phpstan-type Predicate \Closure(\Illuminate\Database\Eloquent\Model&\Vusys\NestedSet\Contracts\HasNestedSet, WalkContext): bool
 */
final readonly class WalkFilter
{
    /**
     * @param  Predicate|null  $visitable
     */
    public function __construct(
        public ?int $maxDepth = null,
        public ?Closure $visitable = null,
        public bool $includeRoot = true,
    ) {}

    /**
     * Depth-limited walk: visit the walk root + `$maxDepth` further levels.
     * Counts relative to the walk root, not the absolute `depth` column.
     *
     * `$maxDepth` must be `>= 0`. A negative value would reject every
     * node — including the walk root, whose depth is `0` — silently
     * yielding an empty walk; we treat that as a programmer error and
     * throw rather than producing a no-op.
     */
    public static function depth(int $maxDepth): self
    {
        if ($maxDepth < 0) {
            throw new \InvalidArgumentException(sprintf(
                'WalkFilter::depth: $maxDepth must be >= 0; got %d. '
                .'A negative maxDepth would reject every node — including the walk root.',
                $maxDepth,
            ));
        }

        return new self(maxDepth: $maxDepth);
    }

    /**
     * Predicate-based walk: visit only nodes where `$predicate` returns
     * true. Pruned nodes' subtrees are skipped too — there is no way to
     * skip a node but visit its children.
     *
     * @param  Predicate  $predicate
     */
    public static function where(Closure $predicate): self
    {
        return new self(visitable: $predicate);
    }

    /**
     * Combines two filters: a node passes the result iff it passes both
     * inputs. The composed `maxDepth` is the stricter of the two; the
     * composed predicate ANDs the inputs; `includeRoot` ANDs as well so
     * a single `includeRoot: false` propagates.
     */
    public static function compose(?self $a, ?self $b): self
    {
        if (! $a instanceof WalkFilter && ! $b instanceof WalkFilter) {
            return new self;
        }
        if (! $a instanceof WalkFilter) {
            return $b;
        }
        if (! $b instanceof WalkFilter) {
            return $a;
        }

        $predA = $a->visitable;
        $predB = $b->visitable;

        if (! $predA instanceof Closure && ! $predB instanceof Closure) {
            $merged = null;
        } elseif (! $predA instanceof Closure) {
            $merged = $predB;
        } elseif (! $predB instanceof Closure) {
            $merged = $predA;
        } else {
            $merged = (static fn (Model&HasNestedSet $node, WalkContext $ctx): bool => $predA($node, $ctx) && $predB($node, $ctx));
        }

        $maxDepth = match (true) {
            $a->maxDepth === null => $b->maxDepth,
            $b->maxDepth === null => $a->maxDepth,
            default => min($a->maxDepth, $b->maxDepth),
        };

        return new self(
            maxDepth: $maxDepth,
            visitable: $merged,
            includeRoot: $a->includeRoot && $b->includeRoot,
        );
    }

    /**
     * Instance-form composition: `$a->andThen($b)` reads slightly more
     * naturally in pipelines than `WalkFilter::compose($a, $b)`. Both
     * exist; both produce the same filter.
     */
    public function andThen(?self $other): self
    {
        return self::compose($this, $other);
    }

    /**
     * Returns true if `$node` should be visited (i.e. the visitor sees
     * it AND the walker descends into its children). When this returns
     * false the walker skips both `$node` and its subtree.
     *
     * Depth check is inclusive: `maxDepth(2)` allows depths 0, 1, 2.
     */
    public function allows(Model&HasNestedSet $node, WalkContext $ctx): bool
    {
        if ($this->maxDepth !== null && $ctx->depth > $this->maxDepth) {
            return false;
        }

        return ! ($this->visitable instanceof Closure && ! ($this->visitable)($node, $ctx));
    }
}
