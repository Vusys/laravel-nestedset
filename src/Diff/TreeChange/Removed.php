<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Diff\TreeChange;

use JsonSerializable;

/**
 * A row present in `before` but absent from `after`. `apply()` issues
 * the model's configured delete (soft or force) for each removed key
 * — descendants that are themselves listed as `Removed` are deleted
 * in their own turn rather than cascading silently.
 *
 * The `parentKey`, `attributes`, and `siblingPosition` fields carry
 * the row's pre-removal shape so that {@see TreeDiff::invert()} can
 * reconstruct an equivalent `Added` when undoing. `apply()` itself
 * ignores them — the key alone is sufficient to delete.
 */
final readonly class Removed implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public mixed $key,
        public mixed $parentKey = null,
        public array $attributes = [],
        public int $siblingPosition = 0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => 'removed',
            'key' => $this->key,
            'parentKey' => $this->parentKey,
            'attributes' => $this->attributes,
            'siblingPosition' => $this->siblingPosition,
        ];
    }
}
