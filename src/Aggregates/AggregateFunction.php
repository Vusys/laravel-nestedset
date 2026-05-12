<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates;

/**
 * The five SQL standard aggregate functions the package supports for
 * precalculated columns.
 *
 * Backed by string so values appear human-readable in error messages,
 * logs, and debug dumps.
 */
enum AggregateFunction: string
{
    case Sum = 'sum';
    case Count = 'count';
    case Avg = 'avg';
    case Min = 'min';
    case Max = 'max';

    /**
     * True for functions whose maintenance can be expressed as a single
     * delta `UPDATE col = col + Δ` on each ancestor row. The remaining
     * functions need a subtree recompute on at least some mutation paths
     * (see {@see Aggregate} class docblock).
     */
    public function supportsDelta(): bool
    {
        return match ($this) {
            self::Sum, self::Count => true,
            self::Avg, self::Min, self::Max => false,
        };
    }

    /**
     * True for functions whose canonical "empty subtree" answer is NULL
     * rather than zero. Aggregate columns of these functions are stored
     * nullable; SUM and COUNT default to 0 and stay non-null.
     */
    public function nullableOnEmpty(): bool
    {
        return match ($this) {
            self::Sum, self::Count => false,
            self::Avg, self::Min, self::Max => true,
        };
    }
}
