<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Lifecycle;

use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\ChangeFeed\ChangeFeedSnapshot;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Definitions\ListenerAggregateDefinition;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicate;

/**
 * Mutable state captured during a model's `saving` hook and consumed
 * during the `saved` hook by {@see DeltaCapture::apply()}.
 *
 * Originally lived as seven separate `private` arrays on the trait —
 * consolidating them into one value object lets {@see DeltaCapture}
 * read and write the state without the trait having to expose seven
 * getters and seven setters. The fields are public-readwrite for
 * brevity; only {@see DeltaCapture} and the lifecycle helpers ever
 * touch them, and the trait clears the whole object on each apply.
 *
 * @internal
 */
final class CapturedMutation
{
    /**
     * Source-column deltas captured in `saving` and applied in `saved`.
     * Keyed by aggregate column name (the SUM column receiving the delta).
     *
     * @var array<string, int|float>
     */
    public array $deltas = [];

    /**
     * Cheap-delta MIN/MAX candidates captured in `saving` (extension or
     * insert direction — the new value can only extend the extremum,
     * never invalidate it).
     *
     * @var array<string, array{function: AggregateFunction, value: int|float}>
     */
    public array $extremes = [];

    /**
     * Recompute candidates captured in `saving` (lost-holder direction
     * — the change may have invalidated the stored extremum on some
     * ancestor).
     *
     * @var array<string, array{function: AggregateFunction, source: string, filterValue: int|float, filter: FilterPredicate|null}>
     */
    public array $recomputes = [];

    /**
     * Listener Min/Max definitions where the stored extremum may be
     * invalidated by a change.
     *
     * @var array<string, ListenerAggregateDefinition>
     */
    public array $listenerRecomputes = [];

    /**
     * Bitwise BitOr / BitXor deltas captured in `saving` / `created` /
     * `deleted`. BitAnd never appears here; it always routes through
     * chain recompute.
     *
     * @var array<string, array{function: AggregateFunction, value: int|float}>
     */
    public array $bitwise = [];

    /**
     * Aggregate definitions that need an ancestor-chain recompute on
     * the next apply pass.
     *
     * @var array<string, AggregateDefinition>
     */
    public array $chainRecomputes = [];

    /**
     * Lazy aggregate columns whose watched columns went dirty on the
     * current save.
     *
     * @var list<string>
     */
    public array $lazyInvalidations = [];

    /**
     * Per-row, per-column aggregate change-feed snapshot taken at the
     * start of a lifecycle hook. NULL means "no listener; skip the
     * work entirely".
     */
    public ?ChangeFeedSnapshot $changeFeedPreSnapshot = null;

    public function isEmpty(): bool
    {
        return $this->deltas === []
            && $this->extremes === []
            && $this->recomputes === []
            && $this->listenerRecomputes === []
            && $this->chainRecomputes === []
            && $this->bitwise === []
            && $this->lazyInvalidations === [];
    }

    public function clearCapture(): void
    {
        $this->deltas = [];
        $this->extremes = [];
        $this->recomputes = [];
        $this->listenerRecomputes = [];
        $this->chainRecomputes = [];
        $this->bitwise = [];
        $this->lazyInvalidations = [];
    }
}
