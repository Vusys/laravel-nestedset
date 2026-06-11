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
     * @param  string|null  $labelColumn  Maps the exporter's display-only `label` field back onto a real column so toJsonTree()→fromJsonTree() round-trips without callers re-adding the column via extras. Defaults to `name` (the exporter's default label source). Set to null to drop `label` entirely (the old behaviour). Never overwrites a value already present for that column.
     */
    public function __construct(
        public string $childrenKey = 'children',
        public bool $strict = true,
        public ?Closure $transform = null,
        public bool $includeKeys = false,
        public array $ignoreColumns = ['lft', 'rgt', 'depth', 'parent_id'],
        public ?string $labelColumn = 'name',
    ) {}
}
