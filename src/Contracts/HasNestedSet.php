<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Contracts;

use Vusys\NestedSet\Concerns\HasNestedSetAggregates;
use Vusys\NestedSet\NodeBounds;
use Vusys\NestedSet\NodeTrait;

/**
 * Contract for models that store themselves in the nested-set encoding.
 *
 * Provides typed accessors over the four maintained columns (lft, rgt,
 * depth, parent_id), the column-name accessors callers / internals use
 * to build queries, and the structural `isPlacedInTree()` placement
 * check. The {@see NodeTrait} supplies default implementations for all
 * of these.
 *
 * Models whose aggregate columns are maintained by
 * {@see HasNestedSetAggregates} should
 * implement the {@see MaintainsTreeAggregates} sub-contract, which
 * extends this one with the trait's public surface. `NodeTrait` users
 * (the standard composition) satisfy that sub-contract automatically.
 */
interface HasNestedSet
{
    public function getLft(): int;

    public function getRgt(): int;

    public function getDepth(): int;

    public function getParentId(): int|string|null;

    public function getBounds(): NodeBounds;

    public function getLftName(): string;

    public function getRgtName(): string;

    public function getDepthName(): string;

    public function getParentIdName(): string;

    /**
     * True when this node's lft/rgt have been assigned via a tree
     * placement (appendToNode, makeRoot, etc.). False for freshly-
     * constructed models whose bounds are still at the migration
     * default of 0.
     *
     * Structural primitive — guards every tree-mutation entry point
     * (HasTreeMutation), the clone pre-checks (HasSubtreeClone), and
     * the aggregate lifecycle hooks against operating on rows that
     * have not been placed yet.
     */
    public function isPlacedInTree(): bool;
}
