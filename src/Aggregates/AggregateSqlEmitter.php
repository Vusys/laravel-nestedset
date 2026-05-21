<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates;

use Illuminate\Database\Connection;
use Vusys\NestedSet\Aggregates\Strategy\RecomputeMaintenance;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Query\TreeAggregateBuilder;

/**
 * Per-driver SQL fragment factory for the collection-aggregate kinds:
 * `DistinctCount`, `StringAgg`, `JsonAgg`, `JsonObjectAgg`.
 *
 * Centralises the backend dispatch so the various aggregate-expression
 * sites in {@see TreeAggregateBuilder} and
 * {@see RecomputeMaintenance}
 * share one implementation. The existing five numeric kinds keep their
 * inline match — they emit identical SQL on every backend and don't
 * need a per-driver branch.
 *
 * All emission methods return raw SQL strings; the caller is responsible
 * for embedding them into a SELECT, derived table, or LATERAL clause.
 * No values are injected through these helpers — only column identifiers
 * the package owns end-to-end (validated against the model's source
 * columns at registry build time) and user-supplied separator strings
 * which are quoted via {@see FilterValueQuoter}.
 */
final class AggregateSqlEmitter
{
    /**
     * Aggregate expression that scans a subtree (descendants joined into
     * the outer node) and returns one scalar value per row.
     *
     * `$sourceQualifier` is the SQL prefix the inner table is exposed
     * under (`'i.'`, `'inner_a.'`, `'d.'`, etc.). `$filterSql` is the
     * already-built WHERE-style predicate when the aggregate is filtered;
     * `null` for the unfiltered fast path.
     */
    public static function emit(
        Connection $connection,
        AggregateDefinition $def,
        string $sourceQualifier,
        ?string $filterSql = null,
    ): string {
        $driver = $connection->getDriverName();

        return match ($def->function) {
            AggregateFunction::DistinctCount => self::emitDistinctCount($def, $sourceQualifier, $filterSql),
            AggregateFunction::StringAgg => self::emitStringAgg($connection, $driver, $def, $sourceQualifier, $filterSql),
            AggregateFunction::JsonAgg => self::emitJsonAgg($connection, $driver, $def, $sourceQualifier, $filterSql),
            AggregateFunction::JsonObjectAgg => self::emitJsonObjectAgg($connection, $driver, $def, $sourceQualifier, $filterSql),
            default => throw new AggregateConfigurationException(sprintf(
                'AggregateSqlEmitter::emit() does not handle %s — only the collection-aggregate kinds belong here.',
                $def->function->value,
            )),
        };
    }

    /**
     * Inline expression that produces the aggregate value for a single
     * leaf row, without going through the LATERAL / derived join.
     * Used by the leaf fast-path: when `rgt = lft + 1`, the subtree is
     * exactly this row, so we can compute the aggregate directly.
     *
     * Inclusive aggregates use the leaf's source value; exclusive
     * aggregates return the "empty subtree" element (0 for distinctCount,
     * NULL for the rest).
     */
    public static function leafInline(
        Connection $connection,
        AggregateDefinition $def,
        string $tableQualifier,
        ?string $filterSql = null,
    ): string {
        if (! $def->inclusive) {
            return match ($def->function) {
                AggregateFunction::DistinctCount => '0',
                AggregateFunction::StringAgg,
                AggregateFunction::JsonAgg,
                AggregateFunction::JsonObjectAgg => 'NULL',
                default => throw new AggregateConfigurationException(sprintf(
                    'AggregateSqlEmitter::leafInline() does not handle %s.',
                    $def->function->value,
                )),
            };
        }

        $driver = $connection->getDriverName();

        return match ($def->function) {
            AggregateFunction::DistinctCount => self::leafDistinctCount($def, $tableQualifier, $filterSql),
            AggregateFunction::StringAgg => self::leafStringAgg($driver, $def, $tableQualifier, $filterSql),
            AggregateFunction::JsonAgg => self::leafJsonAgg($connection, $driver, $def, $tableQualifier, $filterSql),
            AggregateFunction::JsonObjectAgg => self::leafJsonObjectAgg($driver, $def, $tableQualifier, $filterSql),
            default => throw new AggregateConfigurationException(sprintf(
                'AggregateSqlEmitter::leafInline() does not handle %s.',
                $def->function->value,
            )),
        };
    }

    private static function emitDistinctCount(AggregateDefinition $def, string $qualifier, ?string $filterSql): string
    {
        $source = self::requireSource($def);
        $ref = $qualifier.$source;
        if ($filterSql === null) {
            return "COUNT(DISTINCT {$ref})";
        }

        return "COUNT(DISTINCT CASE WHEN {$filterSql} THEN {$ref} ELSE NULL END)";
    }

    private static function leafDistinctCount(AggregateDefinition $def, string $tableQualifier, ?string $filterSql): string
    {
        $source = self::requireSource($def);
        $ref = $tableQualifier.$source;
        $base = "CASE WHEN {$ref} IS NULL THEN 0 ELSE 1 END";

        if ($filterSql === null) {
            return $base;
        }

        return "CASE WHEN ({$filterSql}) AND {$ref} IS NOT NULL THEN 1 ELSE 0 END";
    }

    private static function emitStringAgg(
        Connection $connection,
        string $driver,
        AggregateDefinition $def,
        string $qualifier,
        ?string $filterSql,
    ): string {
        $source = self::requireSource($def);
        $ref = $qualifier.$source;
        $sep = self::quoteString($connection, $def->separator);
        $orderBy = $def->orderBy !== null ? $qualifier.$def->orderBy : null;
        $distinctKw = $def->distinct ? 'DISTINCT ' : '';

        // Source value used inside the aggregator. For filter, wrap in CASE
        // so non-matching rows produce NULL (all string aggregators skip NULL).
        $valueExpr = match ($driver) {
            'pgsql' => "{$ref}::text",
            default => $ref,
        };
        if ($filterSql !== null) {
            $valueExpr = "CASE WHEN {$filterSql} THEN {$valueExpr} ELSE NULL END";
        }

        return match ($driver) {
            'pgsql' => self::pgStringAgg($valueExpr, $sep, $orderBy, $distinctKw),
            'mysql', 'mariadb' => self::mysqlStringAgg($valueExpr, $sep, $orderBy, $distinctKw),
            'sqlite' => self::sqliteStringAgg($valueExpr, $sep, $distinctKw),
            default => throw self::unsupportedDriver('stringAgg', $driver),
        };
    }

    private static function pgStringAgg(string $valueExpr, string $sep, ?string $orderBy, string $distinctKw): string
    {
        // PG only accepts ORDER BY columns that appear in the DISTINCT set; the
        // attribute-construction guard already enforces that orderBy == source
        // when distinct is set, so it's safe to emit ORDER BY here in both
        // cases.
        $orderClause = $orderBy !== null ? " ORDER BY {$orderBy}" : '';

        return "STRING_AGG({$distinctKw}{$valueExpr}, {$sep}{$orderClause})";
    }

    private static function mysqlStringAgg(string $valueExpr, string $sep, ?string $orderBy, string $distinctKw): string
    {
        $orderClause = $orderBy !== null ? " ORDER BY {$orderBy}" : '';

        return "GROUP_CONCAT({$distinctKw}{$valueExpr}{$orderClause} SEPARATOR {$sep})";
    }

    private static function sqliteStringAgg(string $valueExpr, string $sep, string $distinctKw): string
    {
        // SQLite's GROUP_CONCAT signature is GROUP_CONCAT(expr, sep). The DISTINCT form
        // does not accept a separator argument — it forces ', ' as the separator. Document
        // this; users who need a custom separator with distinct should use PG/MySQL.
        if ($distinctKw !== '') {
            return "GROUP_CONCAT(DISTINCT {$valueExpr})";
        }

        return "GROUP_CONCAT({$valueExpr}, {$sep})";
    }

    private static function leafStringAgg(string $driver, AggregateDefinition $def, string $tableQualifier, ?string $filterSql): string
    {
        $source = self::requireSource($def);
        $ref = $tableQualifier.$source;
        $valueExpr = match ($driver) {
            'pgsql' => "{$ref}::text",
            default => "CAST({$ref} AS CHAR)",
        };
        if ($driver === 'sqlite') {
            $valueExpr = $ref;
        }

        if ($filterSql !== null) {
            return "CASE WHEN ({$filterSql}) THEN {$valueExpr} ELSE NULL END";
        }

        return $valueExpr;
    }

    private static function emitJsonAgg(
        Connection $connection,
        string $driver,
        AggregateDefinition $def,
        string $qualifier,
        ?string $filterSql,
    ): string {
        $orderBy = $def->orderBy !== null ? $qualifier.$def->orderBy : null;

        $valueExpr = self::jsonAggValueExpression($connection, $driver, $def, $qualifier);

        if ($filterSql !== null && $driver === 'pgsql') {
            // PG's FILTER clause keeps null rows out of the JSON array without
            // requiring null-wrapping the value (which would otherwise produce
            // [null, null, ...]).
            return self::pgJsonAggInner($valueExpr, $orderBy)." FILTER (WHERE {$filterSql})";
        }

        if ($filterSql !== null) {
            // MySQL/MariaDB/SQLite: JSON_*_ARRAY / GROUP-style aggregators
            // include null entries unconditionally. Document this — `filter()`
            // on these backends is implemented by wrapping the value, which
            // can produce nulls in the resulting array for non-matching rows.
            $valueExpr = "CASE WHEN {$filterSql} THEN {$valueExpr} ELSE NULL END";
        }

        return match ($driver) {
            'pgsql' => self::pgJsonAggInner($valueExpr, $orderBy),
            'mysql', 'mariadb' => "JSON_ARRAYAGG({$valueExpr})",
            'sqlite' => "JSON_GROUP_ARRAY({$valueExpr})",
            default => throw self::unsupportedDriver('jsonAgg', $driver),
        };
    }

    private static function pgJsonAggInner(string $valueExpr, ?string $orderBy): string
    {
        $orderClause = $orderBy !== null ? " ORDER BY {$orderBy}" : '';

        return "JSON_AGG({$valueExpr}{$orderClause})";
    }

    /**
     * Builds the per-row value expression that goes inside the JSON_AGG /
     * JSON_ARRAYAGG / JSON_GROUP_ARRAY call. For scalar `source`, that's
     * just the column reference. For multi-column sources, that's a
     * `JSON_BUILD_OBJECT('k', t.c, …)` (or backend equivalent).
     */
    private static function jsonAggValueExpression(
        Connection $connection,
        string $driver,
        AggregateDefinition $def,
        string $qualifier,
    ): string {
        if ($def->source !== null) {
            return $qualifier.$def->source;
        }

        if ($def->sources === []) {
            throw new AggregateConfigurationException(
                'jsonAgg definition has neither a scalar source nor a sources map.',
            );
        }

        return self::buildJsonObject($connection, $driver, $def->sources, $qualifier);
    }

    /**
     * Emits a per-backend single-row JSON object literal:
     *   PG:      JSON_BUILD_OBJECT('k1', t.c1, 'k2', t.c2, ...)
     *   MySQL:   JSON_OBJECT('k1', t.c1, 'k2', t.c2, ...)
     *   SQLite:  JSON_OBJECT('k1', t.c1, 'k2', t.c2, ...)
     *
     * @param  array<string,string>  $sources
     */
    private static function buildJsonObject(Connection $connection, string $driver, array $sources, string $qualifier): string
    {
        $parts = [];
        foreach ($sources as $jsonKey => $sourceCol) {
            $parts[] = self::quoteString($connection, $jsonKey);
            $parts[] = $qualifier.$sourceCol;
        }
        $argList = implode(', ', $parts);

        return match ($driver) {
            'pgsql' => "JSON_BUILD_OBJECT({$argList})",
            'mysql', 'mariadb', 'sqlite' => "JSON_OBJECT({$argList})",
            default => throw self::unsupportedDriver('jsonAgg/jsonObjectAgg object construction', $driver),
        };
    }

    private static function leafJsonAgg(Connection $connection, string $driver, AggregateDefinition $def, string $tableQualifier, ?string $filterSql): string
    {
        $value = self::jsonAggValueExpression($connection, $driver, $def, $tableQualifier);

        $inner = match ($driver) {
            'pgsql' => "JSON_BUILD_ARRAY({$value})",
            'mysql', 'mariadb', 'sqlite' => "JSON_ARRAY({$value})",
            default => throw self::unsupportedDriver('jsonAgg leaf inline', $driver),
        };

        if ($filterSql === null) {
            return $inner;
        }

        return "CASE WHEN ({$filterSql}) THEN {$inner} ELSE NULL END";
    }

    private static function emitJsonObjectAgg(
        Connection $connection,
        string $driver,
        AggregateDefinition $def,
        string $qualifier,
        ?string $filterSql,
    ): string {
        unset($connection);

        $key = $def->keyColumn ?? throw new AggregateConfigurationException(
            'jsonObjectAgg definition is missing keyColumn.',
        );
        $value = $def->valueColumn ?? throw new AggregateConfigurationException(
            'jsonObjectAgg definition is missing valueColumn.',
        );

        $keyRef = $qualifier.$key;
        $valueRef = $qualifier.$value;

        $keyExpr = match ($driver) {
            'pgsql' => "{$keyRef}::text",
            default => $keyRef,
        };

        if ($driver === 'pgsql') {
            $filterParts = [];
            if ($filterSql !== null) {
                $filterParts[] = "({$filterSql})";
            }
            if (! $def->allowNullKeys) {
                $filterParts[] = "{$keyRef} IS NOT NULL";
            }
            $filterClause = $filterParts === []
                ? ''
                : ' FILTER (WHERE '.implode(' AND ', $filterParts).')';

            return self::pgJsonObjectAgg($keyExpr, $valueRef, self::pgOrderByClause($def, $qualifier)).$filterClause;
        }

        // MySQL / MariaDB / SQLite: emulate FILTER via key-null trick.
        // JSON_OBJECTAGG / JSON_GROUP_OBJECT both skip rows where the key
        // is NULL — wrap the key expression so non-matching rows produce
        // a NULL key. This means $allowNullKeys: true plus a filter is
        // not preservable on these backends; documented as a trade-off.
        $guardParts = [];
        if (! $def->allowNullKeys) {
            $guardParts[] = "{$keyRef} IS NOT NULL";
        }
        if ($filterSql !== null) {
            $guardParts[] = "({$filterSql})";
        }
        if ($guardParts !== []) {
            $guard = implode(' AND ', $guardParts);
            $keyExpr = "CASE WHEN {$guard} THEN {$keyExpr} ELSE NULL END";
        }

        return match ($driver) {
            'mysql', 'mariadb' => self::mysqlJsonObjectAgg($keyExpr, $valueRef),
            'sqlite' => self::sqliteJsonObjectAgg($keyExpr, $valueRef),
            default => throw self::unsupportedDriver('jsonObjectAgg', $driver),
        };
    }

    private static function pgOrderByClause(AggregateDefinition $def, string $qualifier): string
    {
        if ($def->orderBy === null) {
            return '';
        }

        return ' ORDER BY '.$qualifier.$def->orderBy;
    }

    private static function pgJsonObjectAgg(string $keyExpr, string $valueRef, string $orderBy): string
    {
        return "JSON_OBJECT_AGG({$keyExpr}, {$valueRef}{$orderBy})";
    }

    private static function mysqlJsonObjectAgg(string $keyExpr, string $valueRef): string
    {
        return "JSON_OBJECTAGG({$keyExpr}, {$valueRef})";
    }

    private static function sqliteJsonObjectAgg(string $keyExpr, string $valueRef): string
    {
        return "JSON_GROUP_OBJECT({$keyExpr}, {$valueRef})";
    }

    private static function leafJsonObjectAgg(string $driver, AggregateDefinition $def, string $tableQualifier, ?string $filterSql): string
    {
        $key = $def->keyColumn ?? throw new AggregateConfigurationException(
            'jsonObjectAgg definition is missing keyColumn.',
        );
        $value = $def->valueColumn ?? throw new AggregateConfigurationException(
            'jsonObjectAgg definition is missing valueColumn.',
        );

        $keyRef = $tableQualifier.$key;
        $valueRef = $tableQualifier.$value;

        $keyExpr = match ($driver) {
            'pgsql' => "{$keyRef}::text",
            default => $keyRef,
        };

        $singleRow = match ($driver) {
            'pgsql' => "JSON_BUILD_OBJECT({$keyExpr}, {$valueRef})",
            'mysql', 'mariadb', 'sqlite' => "JSON_OBJECT({$keyExpr}, {$valueRef})",
            default => throw self::unsupportedDriver('jsonObjectAgg leaf inline', $driver),
        };

        $guardParts = [];
        if (! $def->allowNullKeys) {
            $guardParts[] = "{$keyRef} IS NOT NULL";
        }
        if ($filterSql !== null) {
            $guardParts[] = "({$filterSql})";
        }

        if ($guardParts === []) {
            return $singleRow;
        }

        $guard = implode(' AND ', $guardParts);

        return "CASE WHEN {$guard} THEN {$singleRow} ELSE NULL END";
    }

    /**
     * For collection aggregates a NULL-source row produces "no contribution":
     * COUNT(DISTINCT NULL) = 0, STRING_AGG skips, JSON_AGG would normally
     * include null — but FILTER handles that on PG; on other backends we
     * already null-wrap when filtered. For unfiltered jsonAgg of a single
     * column on MySQL / SQLite, null entries DO appear in the output —
     * documented behaviour.
     */
    public static function requireSource(AggregateDefinition $def): string
    {
        if ($def->source === null) {
            throw new AggregateConfigurationException(sprintf(
                'AggregateDefinition for column "%s" (%s) requires a scalar source column for this code path.',
                $def->column,
                $def->function->value,
            ));
        }

        return $def->source;
    }

    /**
     * Returns the columns whose values participate in maintenance for this
     * definition — used by drift checking and watch-set computation.
     *
     * @return list<string>
     */
    public static function watchColumns(AggregateDefinition $def): array
    {
        return match ($def->function) {
            AggregateFunction::JsonObjectAgg => [
                $def->keyColumn ?? throw new AggregateConfigurationException(
                    'jsonObjectAgg definition is missing keyColumn.',
                ),
                $def->valueColumn ?? throw new AggregateConfigurationException(
                    'jsonObjectAgg definition is missing valueColumn.',
                ),
            ],
            AggregateFunction::JsonAgg => $def->source !== null
                ? [$def->source]
                : array_values($def->sources),
            default => $def->source !== null ? [$def->source] : [],
        };
    }

    private static function quoteString(Connection $connection, string $value): string
    {
        return FilterValueQuoter::quote($connection, $value);
    }

    private static function unsupportedDriver(string $kind, string $driver): AggregateConfigurationException
    {
        return new AggregateConfigurationException(sprintf(
            'AggregateSqlEmitter: driver "%s" is not supported for %s.',
            $driver,
            $kind,
        ));
    }
}
