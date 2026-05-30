<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Export;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Walker\WalkFilter;

/**
 * Knobs for {@see TreeExporter::toAsciiTree()}.
 *
 * @phpstan-type LabelClosure Closure(Model): (string|int|float|null)
 */
final readonly class AsciiOptions
{
    /**
     * @param  LabelClosure|null  $label
     * @param  WalkFilter|null  $filter  Optional walker filter (depth +
     *                                   predicate). Composes with
     *                                   `$maxDepth` if both set — the
     *                                   stricter depth wins.
     */
    public function __construct(
        public bool $unicode = true,
        public ?Closure $label = null,
        public bool $showDepth = false,
        public ?int $maxDepth = null,
        public bool $withTrashed = false,
        public ?WalkFilter $filter = null,
    ) {}
}
