<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Export;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Knobs for {@see TreeExporter::toDot()}.
 *
 * @phpstan-type LabelClosure Closure(Model): (string|int|float|null)
 */
final readonly class DotOptions
{
    /**
     * @param  'TB'|'LR'|'BT'|'RL'  $direction
     * @param  LabelClosure|null  $label
     * @param  list<string>  $showAggregates
     */
    public function __construct(
        public string $direction = 'TB',
        public ?Closure $label = null,
        public bool $showId = false,
        public array $showAggregates = [],
        public bool $withTrashed = false,
    ) {}
}
