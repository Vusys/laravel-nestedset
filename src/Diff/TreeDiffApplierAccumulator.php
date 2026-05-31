<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Diff;

/**
 * Mutable accumulator threaded through the `TreeDiffApplier`'s
 * remove/add/move/modify phases. Each list stores the identity keys
 * actually written, in the order they were applied.
 *
 * Lives next to {@see TreeDiffApplier} as an internal collaborator —
 * not part of the public surface.
 *
 * @internal
 */
final class TreeDiffApplierAccumulator
{
    /** @var list<int|string> */
    public array $added = [];

    /** @var list<int|string> */
    public array $removed = [];

    /** @var list<int|string> */
    public array $moved = [];

    /** @var list<int|string> */
    public array $modified = [];
}
