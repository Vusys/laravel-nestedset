<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Query;

use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Expression;

/**
 * Internal SQL fragment built from validated identifiers (column names,
 * tables, and integer positions) the package owns end-to-end.
 *
 * Why: Laravel's {@see Expression} is annotated
 * `@template TValue of literal-string|int|float`, which rules out dynamically
 * composed SQL even when every input is a trusted package-internal value.
 */
final readonly class TreeExpression implements ExpressionContract
{
    public function __construct(private string $sql) {}

    public function getValue(Grammar $grammar): string
    {
        return $this->sql;
    }
}
