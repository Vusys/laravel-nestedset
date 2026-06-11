<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Concerns;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\Numeric;
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
        // A never-placed node (lft/rgt still null on a fresh model) has
        // no bounds to subtract — getRgt()/getLft() would throw. It is
        // simply not a leaf, matching the persisted-unplaced (0,0) row
        // which already answers false.
        if (! $this->isPlacedInTree()) {
            return false;
        }

        return $this->getRgt() - $this->getLft() === 1;
    }

    public function isChild(): bool
    {
        return ! $this->isRoot();
    }

    /**
     * True when this node's lft/rgt have been assigned via a tree
     * placement (appendToNode, makeRoot, etc.). False for freshly-
     * constructed models whose bounds are still at the migration
     * default of 0.
     */
    public function isPlacedInTree(): bool
    {
        $lft = Numeric::asIntOrZero($this->getAttribute($this->getLftName()));
        $rgt = Numeric::asIntOrZero($this->getAttribute($this->getRgtName()));

        return $lft > 0 && $rgt > $lft;
    }

    public function isDescendantOf(HasNestedSet $other): bool
    {
        // A never-placed node can't stand in any ancestry relation, and
        // reading its bounds would throw. Return false cleanly — the
        // same answer a persisted-but-unplaced (0,0) row gives — instead
        // of a bare LogicException. (docs/querying/inspection.md
        // constructs exactly this case.)
        if (! $this->isPlacedInTree() || ! $other->isPlacedInTree()) {
            return false;
        }

        return $this->inSameScopeAs($other)
            && $other->getBounds()->contains($this->getBounds());
    }

    public function isAncestorOf(HasNestedSet $other): bool
    {
        if (! $this->isPlacedInTree() || ! $other->isPlacedInTree()) {
            return false;
        }

        return $this->inSameScopeAs($other)
            && $this->getBounds()->contains($other->getBounds());
    }

    /**
     * Bounds comparisons are only meaningful within a single tree: every
     * scope restarts lft at 1, so two nodes in different trees can have
     * overlapping (even identical) bounds. Guard the bounds-only
     * predicates the same way {@see isSiblingOf()} guards parent_id.
     * Non-Model stubs (contract-only test doubles) have no scope to
     * resolve, so they fall through as same-scope.
     */
    private function inSameScopeAs(HasNestedSet $other): bool
    {
        if ($this instanceof Model && $other instanceof Model) {
            return NestedSetScopeResolver::sameScope($this, $other);
        }

        return true;
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
