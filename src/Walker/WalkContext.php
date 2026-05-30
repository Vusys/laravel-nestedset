<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Walker;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\HasNestedSet;

/**
 * Per-visit context object passed as the second argument to every
 * visitor closure registered with {@see SubtreeWalker::walk()}.
 *
 * Stable visitor signature is `function (Model $node, WalkContext $ctx)`.
 * Whenever the walker grows a new affordance the visitor might want
 * (visit number, accumulated path, child count, ...), it lands here as a
 * new property or method — the visitor signature does not change.
 *
 * All depth/sibling values are relative to the **walk root**, not the
 * absolute `depth` column on the stored node. The absolute depth is
 * still on `$node` if a caller wants it.
 */
final readonly class WalkContext
{
    public bool $isFirstSibling;

    public bool $isLastSibling;

    public function __construct(
        public int $depth,
        public ?Model $parent,
        public int $siblingIndex,
        public int $siblingCount,
        private SubtreeWalker $walker,
        private Model&HasNestedSet $node,
    ) {
        $this->isFirstSibling = $siblingIndex === 0;
        // Single-child case: siblingCount=1, siblingIndex=0 ⇒ both flags true.
        $this->isLastSibling = $siblingIndex === max(0, $siblingCount - 1);
    }

    /**
     * Chain of ancestors from the current node up to (but excluding)
     * the walk root. Empty list at the walk root itself.
     *
     * Computed on first call, not at construction — every visitor pays
     * for `$ctx->depth` and `$ctx->parent`, but ancestor walks beyond
     * that are uncommon, so the index lookup happens lazily.
     *
     * @return list<Model&HasNestedSet>
     */
    public function pathToRoot(): array
    {
        return $this->walker->pathToRootFrom($this->node);
    }
}
