<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Contract every user-defined listener aggregate must implement.
 *
 * A listener aggregate computes each node's numeric contribution to an
 * aggregate column using arbitrary PHP logic — useful for expressions that
 * cannot be expressed as a single SQL aggregate function (e.g.
 * SUM(base_power * level) where the product requires PHP).
 *
 * Implementations are instantiated at maintenance time; they must be
 * stateless (or at least safe to re-instantiate per maintenance cycle).
 */
interface TreeAggregateListener
{
    /**
     * Returns this node's numeric contribution to the aggregate.
     *
     * Return null to exclude the node from the aggregate. For Sum the
     * end value is the same as returning 0 (both add nothing); for
     * Count, Avg, Min, and Max returning null and returning 0 produce
     * different results — null skips the row entirely (no +1 to count,
     * no extremum candidate), 0 still counts as a contributing row.
     *
     * @param  Model  $node  the node being evaluated
     */
    public function contribution(Model $node): int|float|null;

    /**
     * The model attribute names whose changes should trigger
     * re-aggregation on ancestors.
     *
     * Return an empty array if the listener's output is unaffected by
     * saved-event attribute changes (rare — usually some column drives
     * the contribution).
     *
     * @return list<string>
     */
    public function watchColumns(): array;
}
