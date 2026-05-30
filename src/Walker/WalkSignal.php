<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Walker;

/**
 * Return value from a visitor passed to {@see SubtreeWalker::walk()}.
 *
 * Returning `null` (or omitting a return) is the common case — continue
 * to the next node. The enum exists so the two non-default signals
 * share one channel:
 *
 *  - `SkipSubtree` — descent stops here; siblings still visit. Honoured
 *    by pre-order DFS and BFS; ignored by post-order DFS (children are
 *    visited first by definition, so there's nothing to skip).
 *  - `Stop` — halt the entire walk; no further visitors are called.
 */
enum WalkSignal
{
    case SkipSubtree;
    case Stop;
}
