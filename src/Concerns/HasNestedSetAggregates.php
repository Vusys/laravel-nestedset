<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Concerns;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\AggregateDefinition;
use Vusys\NestedSet\Aggregates\AggregateRegistry;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Query\TreeAggregateBuilder;

/**
 * Model-level read methods for precalculated aggregate columns.
 *
 * Phase B exposes only the fresh-read path. Stored columns are
 * available via the model's normal attribute access ($area->tickets_total);
 * this trait adds the *recomputed* counterpart for audit / drift
 * detection.
 *
 * @mixin Model
 * @mixin HasNestedSet
 */
trait HasNestedSetAggregates
{
    /**
     * The user-facing aggregate definitions declared on this model.
     * Excludes internal companions auto-promoted alongside AVG
     * declarations — those are an implementation detail of the
     * maintenance machinery, not part of the public read surface.
     *
     * @return list<AggregateDefinition>
     */
    public function getAggregateDefinitions(): array
    {
        $userFacing = [];

        foreach (AggregateRegistry::for(static::class) as $definition) {
            if (! $definition->isInternal()) {
                $userFacing[] = $definition;
            }
        }

        return $userFacing;
    }

    /**
     * Recomputes the value of an aggregate column for this node by
     * running a subquery against the source column. Reads the stored
     * column via `$model->{$column}`; this method always returns truth
     * from the data, which is why it is more expensive.
     *
     * @throws AggregateConfigurationException when $column is not a
     *                                         declared aggregate on this model.
     */
    public function freshAggregate(string $column): mixed
    {
        $definition = $this->resolveAggregateDefinition($column);

        return TreeAggregateBuilder::scalar($this, $definition);
    }

    private function resolveAggregateDefinition(string $column): AggregateDefinition
    {
        foreach (AggregateRegistry::for(static::class) as $definition) {
            if ($definition->column === $column) {
                return $definition;
            }
        }

        throw new AggregateConfigurationException(sprintf(
            '%s has no aggregate column "%s". '
            .'Declare it via #[NestedSetAggregate(...)] or nestedSetAggregates().',
            static::class,
            $column,
        ));
    }
}
