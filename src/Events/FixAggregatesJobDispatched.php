<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events;

use Vusys\NestedSet\Concerns\HasNestedSetAggregates;

/**
 * Fires synchronously inside
 * {@see HasNestedSetAggregates::queueFixAggregates()}
 * immediately after the job is handed to Laravel's dispatcher. Lets
 * users track queue depth, end-to-end repair latency (compare with
 * the matching {@see FixAggregatesCompleted} from the worker), or
 * alert on "fixAggregates job dispatched outside of expected
 * maintenance window".
 */
final readonly class FixAggregatesJobDispatched
{
    public function __construct(
        public string $modelClass,
        public ?int $anchorId,
        public ?int $chunkSize,
        public ?string $onConnection,
        public ?string $onQueue,
    ) {}
}
