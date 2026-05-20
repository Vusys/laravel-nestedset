<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Concerns;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

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
    private bool $nodeMoved = false;

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

    /**
     * Same parent, same tree. For roots (parent_id IS NULL) the
     * parent_id check is not on its own scope-isolating: every scope
     * has its own NULL-parent roots and a multi-tree table can hold
     * two unrelated roots sharing parent_id = null. The scope-equality
     * check below keeps cross-scope roots from reporting as siblings,
     * matching the convention `children()`, `prevSibling()`, and
     * `nextSibling()` already follow.
     */
    public function isSiblingOf(HasNestedSet $other): bool
    {
        if ($other === $this) {
            return false;
        }

        if ($this->getParentId() !== $other->getParentId()) {
            return false;
        }

        if ($this instanceof Model && $other instanceof Model) {
            return NestedSetScopeResolver::sameScope($this, $other);
        }

        return true;
    }

    /**
     * Returns rgt - lft + 1 — the number of "slots" this subtree
     * occupies in the lft/rgt sequence. A leaf has size 2; a subtree
     * of N nodes (counting self) has size 2N.
     *
     * Use this when you need the lft/rgt slot count (e.g. computing
     * the gap size for a bulk insert). For the count of descendants
     * (not slots), see {@see getDescendantCount()}.
     */
    public function getSubtreeSize(): int
    {
        return $this->getRgt() - $this->getLft() + 1;
    }

    /**
     * @deprecated Use {@see getSubtreeSize()} — the old name suggested
     *             tree-theory "height" (max depth of a descendant)
     *             but the method actually returns the lft/rgt slot
     *             count. Will be removed before 1.0.
     */
    public function getNodeHeight(): int
    {
        return $this->getSubtreeSize();
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
        return $this->nodeMoved;
    }

    /** @internal */
    public function markMoved(bool $moved = true): void
    {
        $this->nodeMoved = $moved;
    }
}
