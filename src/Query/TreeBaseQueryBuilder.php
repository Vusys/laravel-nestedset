<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Query;

use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Vusys\NestedSet\Query\Aggregates\Read\FreshAggregateProjector;

/**
 * Custom base query builder so we can hook the SQL right before it is
 * executed. Today the only consumer is the MariaDB fresh-aggregate read
 * path — see {@see FreshAggregateProjector::applyMariaDbDerivedFreshSelects()}
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

        return $this->connection->select(
            $this->splitMaterializedOffSql(),
            $this->getBindings(),
            ! $this->useWritePdo,
        );
    }

    /**
     * `cursor()` (and `lazy()`, which builds on it) bypasses
     * {@see runSelect()}, so the split_materialized hint would otherwise be
     * silently dropped on streamed reads. Mirror the base generator but
     * prepend the same `SET STATEMENT` prefix. fetchUsing is intentionally
     * not forwarded — it's a newer, optional refinement and omitting it
     * keeps this override portable across the supported Laravel matrix.
     *
     * @return LazyCollection<int, \stdClass>
     */
    #[\Override]
    public function cursor()
    {
        if (! $this->mariaDbSplitMaterializedOff) {
            /** @var LazyCollection<int, \stdClass> */
            return parent::cursor();
        }

        if ($this->columns === null) {
            $this->columns = ['*'];
        }

        $sql = $this->splitMaterializedOffSql();
        $bindings = $this->getBindings();
        $useReadPdo = ! $this->useWritePdo;

        /** @var LazyCollection<int, \stdClass> */
        return (new LazyCollection(function () use ($sql, $bindings, $useReadPdo): \Generator {
            yield from $this->connection->cursor($sql, $bindings, $useReadPdo);
        }))
            ->map(function (mixed $item): mixed {
                /** @var Collection<int, mixed> $batch */
                $batch = $this->applyAfterQueryCallbacks(new Collection([$item]));

                return $batch->first();
            })
            ->reject(fn (mixed $item): bool => $item === null);
    }

    private function splitMaterializedOffSql(): string
    {
        return "SET STATEMENT optimizer_switch='split_materialized=off' FOR "
            .$this->toSql();
    }
}
