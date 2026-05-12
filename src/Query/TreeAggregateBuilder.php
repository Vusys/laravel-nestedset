<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Query;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\AggregateDefinition;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\AggregateRegistry;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * Builds aggregate-related SQL for the fresh read path.
 *
 * Two entry points:
 *  - {@see applyFreshSelects()} — adds correlated-subquery SELECT
 *    columns to an existing {@see TreeQueryBuilder} so an entire result
 *    set returns freshly-computed aggregates alongside stored ones.
 *  - {@see scalar()} — runs a single-row subquery for one node's value,
 *    used by `$model->freshAggregate('col')`.
 *
 * Phase B: read paths only. Maintenance writes land in later phases via
 * different methods on this class.
 */
final class TreeAggregateBuilder
{
    /**
     * Adds one correlated-subquery SELECT per requested aggregate to
     * the underlying query builder.
     *
     * `$request` shapes:
     *  - null / empty array → every user-facing declared aggregate.
     *  - list<string>       → declared aggregate column names to fetch.
     *  - array<string,Aggregate> → ad-hoc aggregates keyed by alias.
     *  - mixed forms of the above are accepted in one call.
     *
     * @param  TreeQueryBuilder<Model>  $builder
     * @param  array<int|string, string|Aggregate>|null  $request
     */
    public static function applyFreshSelects(TreeQueryBuilder $builder, ?array $request = null): void
    {
        $model = $builder->getModel();

        if (! $model instanceof HasNestedSet) {
            throw new AggregateConfigurationException(sprintf(
                '%s does not implement HasNestedSet — withFreshAggregates() is not applicable.',
                $model::class,
            ));
        }

        $resolved = self::resolveRequest($model, $request);

        if ($resolved === []) {
            return;
        }

        $table = $model->getTable();
        $lftCol = $builder->lftColumn();
        $rgtCol = $builder->rgtColumn();
        $scopeCols = NestedSetScopeResolver::columns($model::class);

        foreach ($resolved as $alias => $definition) {
            $sql = self::buildCorrelatedSubquery($table, $lftCol, $rgtCol, $scopeCols, $definition);

            $builder->addSelect(['*', new TreeExpression("({$sql}) as {$alias}")]);
        }
    }

    /**
     * Computes the fresh value of one declared aggregate for a single
     * node, by running a non-correlated subquery scoped to the node's
     * bounds.
     */
    public static function scalar(Model&HasNestedSet $node, AggregateDefinition $definition): mixed
    {
        $bounds = $node->getBounds();
        $table = $node->getTable();
        $lftCol = $node->getLftName();
        $rgtCol = $node->getRgtName();
        $aggregateExpr = self::aggregateExpression($definition, qualifier: '');
        $boundsClause = $definition->inclusive
            ? "{$lftCol} >= ? AND {$rgtCol} <= ?"
            : "{$lftCol} > ? AND {$rgtCol} < ?";

        $bindings = [$bounds->lft, $bounds->rgt];

        $scopeValues = NestedSetScopeResolver::valuesFor($node);
        $scopeClause = '';
        foreach ($scopeValues as $column => $value) {
            $scopeClause .= " AND {$column} = ?";
            $bindings[] = $value;
        }

        $sql = "SELECT {$aggregateExpr} AS aggregate FROM {$table} WHERE {$boundsClause}{$scopeClause}";

        return $node->getConnection()->scalar($sql, $bindings);
    }

    /**
     * Resolves a heterogeneous request array into [alias => definition]
     * pairs. Declared-column-name entries (string values) are looked up
     * in the registry; {@see Aggregate}-instance entries are accepted
     * as ad-hoc declarations, materialised with the array key as the
     * column alias.
     *
     * @param  array<int|string, string|Aggregate>|null  $request
     * @return array<string, AggregateDefinition>
     */
    private static function resolveRequest(Model&HasNestedSet $model, ?array $request): array
    {
        if ($request === null || $request === []) {
            return self::userFacingDeclared($model);
        }

        $resolved = [];

        foreach ($request as $key => $value) {
            if (is_string($value)) {
                $definition = self::findDeclared($model, $value);
                $resolved[$value] = $definition;

                continue;
            }

            if (! is_string($key)) {
                throw new AggregateConfigurationException(
                    'withFreshAggregates(): ad-hoc Aggregate entries must be keyed by a string column alias '
                    .'(e.g. ["subtree_tickets" => Aggregate::sum("tickets")]).',
                );
            }

            $resolved[$key] = $value->into($key);
        }

        return $resolved;
    }

    /**
     * @return array<string, AggregateDefinition>
     */
    private static function userFacingDeclared(Model&HasNestedSet $model): array
    {
        $resolved = [];

        foreach (AggregateRegistry::for($model::class) as $definition) {
            if ($definition->isInternal()) {
                continue;
            }
            $resolved[$definition->column] = $definition;
        }

        return $resolved;
    }

    private static function findDeclared(Model&HasNestedSet $model, string $column): AggregateDefinition
    {
        foreach (AggregateRegistry::for($model::class) as $definition) {
            if ($definition->column === $column) {
                return $definition;
            }
        }

        throw new AggregateConfigurationException(sprintf(
            '%s has no aggregate column "%s". Declare it via #[NestedSetAggregate(...)] or nestedSetAggregates(), '
            .'or pass an Aggregate instance for an ad-hoc fresh read.',
            $model::class,
            $column,
        ));
    }

    /**
     * @param  list<string>  $scopeCols
     */
    private static function buildCorrelatedSubquery(
        string $table,
        string $lftCol,
        string $rgtCol,
        array $scopeCols,
        AggregateDefinition $definition,
    ): string {
        $aggregateExpr = self::aggregateExpression($definition, qualifier: 'd.');

        $boundsClause = $definition->inclusive
            ? "d.{$lftCol} >= {$table}.{$lftCol} AND d.{$rgtCol} <= {$table}.{$rgtCol}"
            : "d.{$lftCol} > {$table}.{$lftCol} AND d.{$rgtCol} < {$table}.{$rgtCol}";

        $scopeClause = '';
        foreach ($scopeCols as $col) {
            $scopeClause .= " AND d.{$col} = {$table}.{$col}";
        }

        return "SELECT {$aggregateExpr} FROM {$table} d WHERE {$boundsClause}{$scopeClause}";
    }

    /**
     * Returns the SQL aggregate expression for a definition. `$qualifier`
     * prefixes the source column (e.g. `d.` inside a correlated subquery,
     * empty for the single-node scalar form which does not need an alias).
     */
    private static function aggregateExpression(AggregateDefinition $definition, string $qualifier): string
    {
        return match ($definition->function) {
            AggregateFunction::Sum => sprintf(
                'COALESCE(SUM(%s%s), 0)',
                $qualifier,
                self::requireSource($definition),
            ),
            AggregateFunction::Count => $definition->source === null
                ? 'COUNT(*)'
                : sprintf('COUNT(%s%s)', $qualifier, $definition->source),
            AggregateFunction::Avg => sprintf(
                'AVG(%s%s)',
                $qualifier,
                self::requireSource($definition),
            ),
            AggregateFunction::Min => sprintf(
                'MIN(%s%s)',
                $qualifier,
                self::requireSource($definition),
            ),
            AggregateFunction::Max => sprintf(
                'MAX(%s%s)',
                $qualifier,
                self::requireSource($definition),
            ),
        };
    }

    private static function requireSource(AggregateDefinition $definition): string
    {
        if ($definition->source === null) {
            throw new AggregateConfigurationException(sprintf(
                'AggregateDefinition for column "%s" (%s) requires a source column.',
                $definition->column,
                $definition->function->value,
            ));
        }

        return $definition->source;
    }
}
