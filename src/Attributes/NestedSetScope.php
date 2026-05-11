<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
readonly class NestedSetScope
{
    /** @param string|string[] $columns */
    public function __construct(
        public string|array $columns,
    ) {}
}
