<?php

declare(strict_types=1);

namespace Vusys\NestedSet;

use Vusys\NestedSet\Contracts\HasNestedSet;

readonly class PendingOperation
{
    public function __construct(
        public string $action,
        public ?HasNestedSet $node = null,
        public Position $position = Position::After,
    ) {}
}
