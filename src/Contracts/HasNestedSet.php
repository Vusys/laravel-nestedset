<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Contracts;

use Vusys\NestedSet\NodeBounds;
use Vusys\NestedSet\NodeTrait;

/**
 * Contract for models that store themselves in the nested-set encoding.
 *
 * Provides typed accessors over the four maintained columns (lft, rgt,
 * depth, parent_id) plus the column-name accessors callers / internals
 * use to build queries. The {@see NodeTrait} supplies
 * default implementations for all of these.
 */
interface HasNestedSet
{
    public function getLft(): int;

    public function getRgt(): int;

    public function getDepth(): int;

    public function getParentId(): ?int;

    public function getBounds(): NodeBounds;

    public function getLftName(): string;

    public function getRgtName(): string;

    public function getDepthName(): string;

    public function getParentIdName(): string;
}
