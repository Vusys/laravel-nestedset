<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Contracts;

use Vusys\NestedSet\NodeBounds;

interface HasNestedSet
{
    public function getLft(): int;

    public function getRgt(): int;

    public function getDepth(): int;

    public function getParentId(): ?int;

    public function getBounds(): NodeBounds;
}
