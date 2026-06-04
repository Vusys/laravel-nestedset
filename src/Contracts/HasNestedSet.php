<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Contracts;

use Closure;
use Vusys\NestedSet\Aggregates\AggregateFixResult;
use Vusys\NestedSet\Concerns\HasNestedSetAggregates;
use Vusys\NestedSet\Jobs\FixAggregatesJob;
use Vusys\NestedSet\NodeBounds;
use Vusys\NestedSet\NodeTrait;

/**
 * Contract for models that store themselves in the nested-set encoding.
 *
 * Provides typed accessors over the four maintained columns (lft, rgt,
 * depth, parent_id) plus the column-name accessors callers / internals
 * use to build queries. The {@see NodeTrait} supplies
 * default implementations for all of these.
 *
 * The `@method` annotations below describe the aggregate-trait surface
 * that {@see HasNestedSetAggregates} adds to
 * every using class. Models that compose `NodeTrait` (the standard
 * path) satisfy both the structural contract and these aggregate
 * helpers; PHPStan needs the annotations to resolve calls made through
 * a `class-string<self>` or `Model&HasNestedSet` intersection type in
 * the aggregate subsystem.
 *
 * @method bool isPlacedInTree()
 * @method mixed freshAggregate(string $column, bool $withTrashed = false)
 * @method void captureAggregateDeltas()
 * @method void applyAggregateDeltas()
 * @method void applyAggregateOnCreate()
 * @method void applyAggregateOnDelete()
 * @method void applyAggregateBeforeMove(NodeBounds $from, string $action)
 * @method void applyAggregateAfterMove(NodeBounds $from, NodeBounds $to, string $action)
 * @method void applyAggregateOnRestore()
 * @method list<AggregateDefinitionContract> getAggregateDefinitions()
 * @method static array<string, int> aggregateErrors(?HasNestedSet $anchor = null)
 * @method static bool aggregatesAreBroken(?HasNestedSet $anchor = null)
 * @method static AggregateFixResult fixAggregates(?HasNestedSet $anchor = null, ?int $chunkSize = null, ?Closure $onChunk = null)
 * @method static array{result: AggregateFixResult, nextAfterId: int|string|null} fixAggregatesChunk(?HasNestedSet $anchor, int|string|null $afterId, int $chunkSize)
 * @method static mixed withDeferredAggregateMaintenance(Closure $work, ?HasNestedSet $anchor = null)
 * @method static FixAggregatesJob queueFixAggregates(?HasNestedSet $anchor = null, ?string $onConnection = null, ?string $onQueue = null, ?int $chunkSize = null)
 * @method static int aggregateDeferredDepth()
 * @method static void incrementAggregateDeferredDepth()
 * @method static void decrementAggregateDeferredDepth()
 */
interface HasNestedSet
{
    public function getLft(): int;

    public function getRgt(): int;

    public function getDepth(): int;

    public function getParentId(): int|string|null;

    public function getBounds(): NodeBounds;

    public function getLftName(): string;

    public function getRgtName(): string;

    public function getDepthName(): string;

    public function getParentIdName(): string;
}
