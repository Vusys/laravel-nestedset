<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Import;

use Closure;
use Vusys\NestedSet\Concerns\HasTreeExport;

/**
 * Knobs for {@see HasTreeExport::fromJsonTree()}.
 *
 * @phpstan-type TransformClosure Closure(array<string, mixed>, int): array<string, mixed>
 */
final readonly class JsonImportOptions
{
    /**
     * @param  TransformClosure|null  $transform  Run per row before validation; lets users rewrite slugs, defaults, or strip metadata.
     * @param  list<string>  $ignoreColumns  Columns to always drop from the payload before insert.
     */
    public function __construct(
        public string $childrenKey = 'children',
        public bool $strict = true,
        public ?Closure $transform = null,
        public bool $includeKeys = false,
        public array $ignoreColumns = ['lft', 'rgt', 'depth', 'parent_id'],
    ) {}
}
