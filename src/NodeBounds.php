<?php

declare(strict_types=1);

namespace Vusys\NestedSet;

readonly class NodeBounds
{
    /**
     * @param  array<string, mixed>  $scope  Scope-column values of the node
     *                                       these bounds came from. Populated
     *                                       by {@see NodeTrait::getBounds()};
     *                                       empty for unscoped models and for
     *                                       bounds built internally without a
     *                                       model. The positional query
     *                                       methods (whereDescendantOf, …)
     *                                       apply these as predicates so a
     *                                       scoped lookup can't leak across
     *                                       trees that all restart lft at 1.
     */
    public function __construct(
        public int $lft,
        public int $rgt,
        public int $depth,
        public array $scope = [],
    ) {}

    public function height(): int
    {
        return $this->rgt - $this->lft + 1;
    }

    /**
     * Returns true when $other is strictly inside this node's bounds,
     * i.e. $other is a descendant of this node.
     */
    public function contains(self $other): bool
    {
        return $this->lft < $other->lft && $other->rgt < $this->rgt;
    }

    /**
     * Depth change going from $this to $other.
     * Positive means $other is deeper, negative means $other is shallower.
     */
    public function depthDelta(self $other): int
    {
        return $other->depth - $this->depth;
    }
}
