<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Diff\TreeChange;

use JsonSerializable;

/**
 * A row whose tree position changed between `before` and `after`.
 *
 * Re-parenting (`$fromParent !== $toParent`) and pure sibling reorder
 * (`$fromParent === $toParent`) both surface here — sibling order is
 * encoded in `lft`, so a row that swaps siblings has nothing else to
 * report. Callers that only care about re-parenting filter on
 * `$fromParent !== $toParent`.
 */
final readonly class Moved implements JsonSerializable
{
    public function __construct(
        public mixed $key,
        public mixed $fromParent,
        public mixed $toParent,
        public int $toSiblingPosition,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => 'moved',
            'key' => $this->key,
            'fromParent' => $this->fromParent,
            'toParent' => $this->toParent,
            'toSiblingPosition' => $this->toSiblingPosition,
        ];
    }
}
