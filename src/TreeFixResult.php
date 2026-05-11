<?php

declare(strict_types=1);

namespace Vusys\NestedSet;

readonly class TreeFixResult
{
    /** @param array<string, int> $errors */
    public function __construct(
        public int $nodesUpdated,
        public array $errors,
    ) {}

    public function hasErrors(): bool
    {
        return array_sum($this->errors) > 0;
    }
}
