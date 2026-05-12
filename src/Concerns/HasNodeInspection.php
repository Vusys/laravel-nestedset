<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Concerns;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\HasNestedSet;

/**
 * Pure read predicates over a node's lft/rgt/parent_id values.
 *
 * Methods here never touch the database — they compare the in-memory
 * attributes already on the model.
 *
 * @mixin Model
 * @mixin HasNestedSet
 */
trait HasNodeInspection
{
    private bool $vusysNodeMoved = false;

    public function isRoot(): bool
    {
        return $this->getParentId() === null;
    }

    public function isLeaf(): bool
    {
        return $this->getRgt() - $this->getLft() === 1;
    }

    public function isChild(): bool
    {
        return ! $this->isRoot();
    }

    public function isDescendantOf(HasNestedSet $other): bool
    {
        return $other->getBounds()->contains($this->getBounds());
    }

    public function isAncestorOf(HasNestedSet $other): bool
    {
        return $this->getBounds()->contains($other->getBounds());
    }

    public function isSiblingOf(HasNestedSet $other): bool
    {
        if ($other === $this) {
            return false;
        }

        return $this->getParentId() === $other->getParentId();
    }

    /**
     * Returns rgt - lft + 1 — the number of "slots" this node occupies in
     * the lft/rgt sequence. A leaf has height 2; a node with N descendants
     * has height 2 * (N + 1).
     */
    public function getNodeHeight(): int
    {
        return $this->getRgt() - $this->getLft() + 1;
    }

    /**
     * Derived from lft/rgt: a subtree of N nodes occupies 2N slots, so the
     * count of strict descendants is (rgt - lft - 1) / 2.
     */
    public function getDescendantCount(): int
    {
        return (int) (($this->getRgt() - $this->getLft() - 1) / 2);
    }

    /**
     * True if this node was moved by a tree mutation during the current
     * request — set by HasTreeMutation::callPendingAction(). Useful for
     * tests and for skipping redundant work in observer hooks.
     */
    public function hasMoved(): bool
    {
        return $this->vusysNodeMoved;
    }

    /** @internal */
    public function markMoved(bool $moved = true): void
    {
        $this->vusysNodeMoved = $moved;
    }
}
