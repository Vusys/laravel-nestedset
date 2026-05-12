<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Vusys\NestedSet\Aggregates\AggregateFixResult;
use Vusys\NestedSet\Contracts\HasNestedSet;

/**
 * Phase N: queued repair of stored aggregate columns.
 *
 * Carries the model class name and an optional anchor id rather than a
 * serialized model instance — anchors can be deleted between dispatch
 * and execution, and re-querying gives us a clear "anchor missing"
 * exception instead of a half-hydrated SerializesModels failure.
 *
 * The handler just calls `Model::fixAggregates($anchor)` — the heavy
 * lifting (drift detection, chunked CASE-WHEN UPDATE) lives in the
 * package's shared aggregate builder, so this job benefits from every
 * Phase K+ / Q optimization automatically.
 *
 * Idempotent: a second run on an already-clean tree finds zero drift
 * and updates nothing. Safe to dispatch defensively.
 */
final class FixAggregatesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  class-string<Model&HasNestedSet>  $modelClass
     */
    public function __construct(
        public readonly string $modelClass,
        public readonly ?int $anchorId = null,
    ) {}

    public function handle(): AggregateFixResult
    {
        $modelClass = $this->modelClass;

        $anchor = null;
        if ($this->anchorId !== null) {
            /** @var Model|null $instance */
            $instance = $modelClass::query()->find($this->anchorId);
            if (! $instance instanceof HasNestedSet) {
                throw new \RuntimeException(sprintf(
                    'FixAggregatesJob: anchor id %d not found on %s — the row was deleted between dispatch and execution.',
                    $this->anchorId,
                    $modelClass,
                ));
            }
            $anchor = $instance;
        }

        // `fixAggregates` is declared on the NodeTrait (always present
        // on a HasNestedSet model in practice), not on the contract —
        // so the call site needs runtime narrowing rather than a static
        // type guarantee. method_exists makes the callable concrete
        // enough for PHPStan; the instanceof check on the return value
        // closes the loop.
        if (! method_exists($modelClass, 'fixAggregates')) {
            throw new \RuntimeException(sprintf(
                'FixAggregatesJob: %s has no static fixAggregates() method — does it use NodeTrait?',
                $modelClass,
            ));
        }

        $result = $modelClass::fixAggregates($anchor);

        if (! $result instanceof AggregateFixResult) {
            throw new \RuntimeException(sprintf(
                'FixAggregatesJob: %s::fixAggregates() returned %s; expected AggregateFixResult.',
                $modelClass,
                get_debug_type($result),
            ));
        }

        return $result;
    }

    /**
     * A stable, human-readable identifier for this job. Used by some
     * queue backends as the de-duplication key and by `php artisan
     * queue:monitor` output. Including the model class + anchor id
     * makes "two repairs for the same tree" easy to spot.
     */
    public function displayName(): string
    {
        return sprintf(
            'fixAggregates(%s%s)',
            $this->modelClass,
            $this->anchorId === null ? '' : "#{$this->anchorId}",
        );
    }
}
