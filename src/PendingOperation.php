<?php

declare(strict_types=1);

namespace Vusys\NestedSet;

use Vusys\NestedSet\Contracts\HasNestedSet;

/**
 * A queued tree mutation, captured on the model and dispatched from its
 * `saving` event hook. `node` is the target neighbour (parent for
 * appendTo/prependTo, sibling for the insertBefore/After operations);
 * makeRoot leaves it null.
 */
readonly class PendingOperation
{
    public function __construct(
        public string $action,
        public ?HasNestedSet $node = null,
        public Position $position = Position::After,
    ) {}
}
