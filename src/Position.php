<?php

declare(strict_types=1);

namespace Vusys\NestedSet;

use Vusys\NestedSet\Concerns\HasTreeMutation;

/**
 * Relative placement for sibling insertion operations.
 *
 * Used by {@see PendingOperation} to disambiguate
 * {@see HasTreeMutation::insertBeforeNode()}
 * from
 * {@see HasTreeMutation::insertAfterNode()}.
 */
enum Position: int
{
    case Before = 1;
    case After = 2;
}
