<?php

declare(strict_types=1);

namespace Vusys\NestedSet;

readonly class NodeBounds
{
    public function __construct(
        public int $lft,
        public int $rgt,
        public int $depth,
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
