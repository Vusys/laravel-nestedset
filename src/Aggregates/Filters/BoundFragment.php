<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Filters;

/**
 * SQL fragment paired with its positional bindings. Threaded through
 * the aggregate-SQL composition pipeline so filter values ride the
 * driver's bound-parameter stream instead of inlining as literals.
 *
 * Bindings are ordered to match `?` placeholder occurrence in `$sql`.
 * When a parent helper splices this fragment's SQL N times into its
 * own output, the parent must repeat these bindings N times in the
 * correct textual order — bindings are positional, so order is the
 * only thing that aligns them with their placeholders.
 */
final readonly class BoundFragment
{
    /**
     * @param  list<scalar|null>  $bindings
     */
    public function __construct(
        public string $sql,
        public array $bindings = [],
    ) {}

    public static function literal(string $sql): self
    {
        return new self($sql);
    }
}
