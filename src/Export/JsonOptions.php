<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Export;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Walker\WalkFilter;

/**
 * Knobs for {@see TreeExporter::toJson()}.
 *
 * @phpstan-type LabelClosure Closure(Model): (string|int|float|null)
 */
final readonly class JsonOptions
{
    /**
     * @param  LabelClosure|null  $label
     * @param  list<string>  $extras  raw column names to copy onto each node payload
     */
    public function __construct(
        public ?Closure $label = null,
        public array $extras = [],
        public string $childrenKey = 'children',
        public bool $withTrashed = false,
        public ?WalkFilter $filter = null,
    ) {}
}
