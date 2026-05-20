<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Export;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Knobs for {@see TreeExporter::toMermaid()}.
 *
 * @phpstan-type LabelClosure Closure(Model): (string|int|float|null)
 */
final readonly class MermaidOptions
{
    private const VALID_DIRECTIONS = ['TD', 'LR', 'BT', 'RL'];

    /**
     * @param  'TD'|'LR'|'BT'|'RL'  $direction
     * @param  LabelClosure|null  $label
     * @param  list<string>  $showAggregates
     */
    public function __construct(
        public string $direction = 'TD',
        public ?Closure $label = null,
        public bool $showId = false,
        public array $showAggregates = [],
        public bool $withTrashed = false,
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
