<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Export;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Walker\WalkFilter;

/**
 * Knobs for {@see TreeExporter::toMermaid()}.
 *
 * @phpstan-type LabelClosure Closure(Model): (string|int|float|null)
 */
final readonly class MermaidOptions
{
    private const array VALID_DIRECTIONS = ['TD', 'LR', 'BT', 'RL'];

    /**
     * @param  string  $direction  One of TD, LR, BT, RL — validated at runtime against self::VALID_DIRECTIONS.
     * @param  LabelClosure|null  $label
     * @param  list<string>  $showAggregates
     */
    public function __construct(
        public string $direction = 'TD',
        public ?Closure $label = null,
        public bool $showId = false,
        public array $showAggregates = [],
        public bool $withTrashed = false,
        public ?WalkFilter $filter = null,
    ) {
        if (! in_array($direction, self::VALID_DIRECTIONS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid Mermaid direction "%s". Expected one of: %s.',
                $direction,
                implode(', ', self::VALID_DIRECTIONS),
            ));
        }
    }
}
