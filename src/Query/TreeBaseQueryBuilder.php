<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Query;

use Illuminate\Database\Query\Builder as BaseBuilder;

/**
 * Custom base query builder so we can hook the SQL right before it is
 * executed. Today the only consumer is the MariaDB fresh-aggregate read
 * path — see {@see TreeAggregateBuilder::applyMariaDbDerivedFreshSelects()}
 * — but the override is intentionally narrow (a single boolean flag) so
 * future planner-coaxing tricks can hang off it without growing into a
 * second hierarchy.
 *
 * The flag is consulted inside {@see self::runSelect()}, which is the
 * single funnel for `get()`, `paginate()`, `chunk()`, etc. — Eloquent
 * never bypasses it for the main data fetch.
 */
class TreeBaseQueryBuilder extends BaseBuilder
{
    private bool $mariaDbSplitMaterializedOff = false;

    /**
     * Marks this builder so the next `runSelect()` prepends
     * `SET STATEMENT optimizer_switch='split_materialized=off' FOR …`
     * to the compiled SQL.
     *
     * MariaDB's planner converts our `applyMariaDbDerivedFreshSelects`
     * derived JOIN into a LATERAL DERIVED via `split_materialized` —
     * collapses the materialise-once advantage we picked the derived
     * shape for, costing ~3× wall-clock. SET STATEMENT scopes the
     * disable to this one statement so session state is never mutated.
     */
    public function withMariaDbSplitMaterializedOff(): static
    {
        $this->mariaDbSplitMaterializedOff = true;

        return $this;
    }

    /**
     * @return array<int, mixed>
     */
    #[\Override]
    protected function runSelect()
    {
        if (! $this->mariaDbSplitMaterializedOff) {
            return parent::runSelect();
        }

        $sql = "SET STATEMENT optimizer_switch='split_materialized=off' FOR "
            .$this->toSql();

        return $this->connection->select(
            $sql,
            $this->getBindings(),
            ! $this->useWritePdo,
        );
    }
}
