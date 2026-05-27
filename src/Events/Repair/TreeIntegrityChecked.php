<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events\Repair;

use Vusys\NestedSet\Concerns\HasTreeRepair;
use Vusys\NestedSet\Events\Aggregates\AggregateDriftDetected;

/**
 * Fires from {@see HasTreeRepair::isBroken()} and
 * {@see HasTreeRepair::countErrors()} after each check completes,
 * regardless of whether anything was found. Useful as a heartbeat
 * for monitoring dashboards ("we ran the check N times today")
 * and to capture the per-category counts for trend analysis.
 *
 * If you only want to alert when drift exists, listen for
 * {@see AggregateDriftDetected} or filter this event on
 * `$totalErrors > 0`.
 *
 * Queue-safe.
 */
final readonly class TreeIntegrityChecked
{
    /**
     * @param  array{invalid_bounds: int, duplicate_lft: int, duplicate_rgt: int, orphans: int}  $errors
     */
    public function __construct(
        public string $modelClass,
        public int|string|null $anchorId,
        public array $errors,
        public int $totalErrors,
    ) {}
}
