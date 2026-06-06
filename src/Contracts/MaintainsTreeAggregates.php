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
 * Sub-contract for models whose aggregate columns are maintained by
 * {@see HasNestedSetAggregates}. Promotes the trait's public surface
 * from `@method` annotations on {@see HasNestedSet} to real signatures
 * so calls through `Model&MaintainsTreeAggregates` (or `class-string`
 * thereof) type-check without virtual-method gymnastics.
 *
 * Every model that composes {@see NodeTrait} (the
 * standard path) implements this contract via the
 * `@phpstan-require-implements` constraint on the trait. Bare
 * {@see HasNestedSet} models (no `NodeTrait`) — used by unit-only
 * walker fixtures and similar — keep the smaller structural surface.
 */
interface MaintainsTreeAggregates extends HasNestedSet
{
    public function freshAggregate(string $column, bool $withTrashed = false): mixed;

    public function captureAggregateDeltas(): void;

    public function applyAggregateDeltas(): void;

    public function applyAggregateOnCreate(): void;

    public function applyAggregateOnDelete(): void;

    public function applyAggregateBeforeMove(NodeBounds $from, string $action): void;

    public function applyAggregateAfterMove(NodeBounds $from, NodeBounds $to, string $action): void;

    public function applyAggregateOnRestore(): void;

    /**
     * @return list<AggregateDefinitionContract>
     */
    public function getAggregateDefinitions(): array;

    /**
     * @return array<string, int>
     */
    public static function aggregateErrors(?HasNestedSet $anchor = null): array;

    public static function aggregatesAreBroken(?HasNestedSet $anchor = null): bool;

    public static function fixAggregates(
        ?HasNestedSet $anchor = null,
        ?int $chunkSize = null,
        ?Closure $onChunk = null,
    ): AggregateFixResult;

    /**
     * @return array{result: AggregateFixResult, nextAfterId: int|string|null}
     */
    public static function fixAggregatesChunk(
        ?HasNestedSet $anchor,
        int|string|null $afterId,
        int $chunkSize,
    ): array;

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function withDeferredAggregateMaintenance(
        Closure $work,
        ?HasNestedSet $anchor = null,
    ): mixed;

    public static function queueFixAggregates(
        ?HasNestedSet $anchor = null,
        ?string $onConnection = null,
        ?string $onQueue = null,
        ?int $chunkSize = null,
    ): FixAggregatesJob;

    /**
     * @internal
     */
    public static function aggregateDeferredDepth(): int;

    /**
     * @internal
     */
    public static function incrementAggregateDeferredDepth(): void;

    /**
     * @internal
     */
    public static function decrementAggregateDeferredDepth(): void;
}
