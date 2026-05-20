<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Concerns\HasSoftDeleteTree;
use Vusys\NestedSet\Contracts\HasNestedSet;

/**
 * Fires inside {@see HasSoftDeleteTree} on the `restoring` Eloquent
 * lifecycle event, after the package has captured the soft-delete
 * timestamp that will be used as the restore marker.
 *
 * The marker is the exact `deleted_at` value the anchor held at
 * the moment restore began — only descendants whose `deleted_at`
 * matches this marker will be restored in the cascade. Listening
 * for this event is how audit chains correlate a restore with the
 * specific soft-delete it's undoing.
 *
 * Niche, but cheap to emit and useful for debugging asymmetric
 * restores (e.g. "why did only some descendants come back?").
 *
 * Not queue-safe — carries a live anchor model. `$marker` alone is
 * queue-safe; capture and forward if needed.
 */
final readonly class SoftDeleteMarkerCaptured
{
    public function __construct(
        public string $modelClass,
        public Model&HasNestedSet $anchor,
        public ?string $marker,
    ) {}
}
