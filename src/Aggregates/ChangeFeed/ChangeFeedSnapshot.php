<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\ChangeFeed;

use Vusys\NestedSet\NodeBounds;

/**
 * Pre-mutation snapshot of the aggregate change-feed columns plus the
 * row-set the post-mutation re-read needs to reproduce. Carried on the
 * mutating model between
 * {@see ChangeFeedRecorder::capture()} and
 * {@see ChangeFeedRecorder::dispatch()}.
 *
 * @internal
 */
final readonly class ChangeFeedSnapshot
{
    /**
     * @param  array<string, mixed>  $scope
     * @param  list<string>  $columns
     * @param  array<int|string, array<string, int|float|bool|string|null>>  $values
     * @param  list<int|string>  $chain
     */
    public function __construct(
        public string $stage,
        public NodeBounds $bounds,
        public array $scope,
        public array $columns,
        public bool $includeSelf,
        public bool $includeSubtree,
        public bool $applySoftDeleteFilter,
        public array $values,
        public array $chain,
    ) {}
}
