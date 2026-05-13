<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events;

use Vusys\NestedSet\Concerns\HasTreeRepair;

/**
 * Fires once when {@see HasTreeRepair::fixTree()}
 * completes its full work — both the structural rebuild and the
 * post-rebuild aggregate repair (where applicable).
 *
 * Useful for: metrics (how often does this fire? how slow on which
 * model?), alerting (fixTree firing in production should be rare),
 * audit logs.
 */
final readonly class FixTreeCompleted
{
    public function __construct(
        public string $modelClass,
        public ?int $anchorId,
        public int $nodesUpdated,
        public float $durationMs,
        /** Total rows whose aggregate columns were corrected as part of the same fixTree call; null when the model declares no aggregates. */
        public ?int $aggregatesFixed,
    ) {}
}
