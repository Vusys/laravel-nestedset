<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Diff\TreeChange;

use JsonSerializable;

/**
 * A row whose non-structural column values changed between `before`
 * and `after`. Only the columns whose values actually differ appear in
 * `$before` / `$after`; identical and ignored columns are stripped.
 *
 * A column that exists in `before` but not in `after` is treated as
 * "no opinion" — `apply()` leaves the column untouched. Callers who
 * want "set to null" pass an explicit null.
 */
final readonly class Modified implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function __construct(
        public mixed $key,
        public array $before,
        public array $after,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => 'modified',
            'key' => $this->key,
            'before' => $this->before,
            'after' => $this->after,
        ];
    }
}
