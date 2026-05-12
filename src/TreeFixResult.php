<?php

declare(strict_types=1);

namespace Vusys\NestedSet;

/**
 * Structured return value of {@see Query\TreeRepairBuilder::fixTree()}.
 *
 * `nodesUpdated` is the row count after repair (the size of the tree
 * the repair walked); `errors` carries the post-repair error counts so
 * a caller can verify the tree converged.
 */
readonly class TreeFixResult
{
    /** @param array<string, int> $errors */
    public function __construct(
        public int $nodesUpdated,
        public array $errors,
    ) {}

    /**
     * True when at least one nested-set invariant is still violated after
     * the repair attempt — typically means a parent_id chain itself was
     * broken (orphan rows or cycles) and the repair couldn't reach every
     * node.
     */
    public function hasErrors(): bool
    {
        return array_sum($this->errors) > 0;
    }
}
