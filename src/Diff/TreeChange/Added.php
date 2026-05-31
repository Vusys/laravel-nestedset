<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Diff\TreeChange;

use JsonSerializable;

/**
 * A row present in `after` but absent from `before`. The diff lists
 * `Added` changes in topological order (parents before children) so
 * `apply()` can insert without waiting on later iterations.
 *
 * `$parentKey` is `null` when the added row is a new root. The value
 * uses the identity key the diff was built with (e.g. an `id`, a
 * `slug`, or whatever the user's closure produced).
 *
 * `$attributes` is the row's column data shape that
 * `fromJsonTree()` consumes — keys are column names, structural
 * columns (`lft`/`rgt`/`depth`/`parent_id`) and the identity key are
 * stripped so the importer doesn't double-write them.
 */
final readonly class Added implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public mixed $key,
        public mixed $parentKey,
        public array $attributes,
        public int $siblingPosition,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => 'added',
            'key' => $this->key,
            'parentKey' => $this->parentKey,
            'attributes' => $this->attributes,
            'siblingPosition' => $this->siblingPosition,
        ];
    }
}
