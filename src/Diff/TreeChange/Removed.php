<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Diff\TreeChange;

use JsonSerializable;

/**
 * A row present in `before` but absent from `after`. `apply()` issues
 * the model's configured delete (soft or force) for each removed key
 * — descendants that are themselves listed as `Removed` are deleted
 * in their own turn rather than cascading silently.
 */
final readonly class Removed implements JsonSerializable
{
    public function __construct(
        public mixed $key,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => 'removed',
            'key' => $this->key,
        ];
    }
}
