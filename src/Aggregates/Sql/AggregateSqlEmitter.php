<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Sql;

use Illuminate\Database\Connection;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Filters\FilterValueQuoter;
use Vusys\NestedSet\Aggregates\Strategy\RecomputeMaintenance;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Query\Aggregates\Read\AggregateSqlFragments;

/**
 * Per-driver SQL fragment factory for the collection-aggregate kinds:
 * `DistinctCount`, `StringAgg`, `JsonAgg`, `JsonObjectAgg`.
 *
 * Centralises the backend dispatch so the various aggregate-expression
 * sites in {@see AggregateSqlFragments} and
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

        // Source value used inside the aggregator. The PG path keeps the raw
        // (cast-only) value for distinct+filter so the FILTER clause can
        // exclude rows without producing a CASE expression that breaks PG's
        // "ORDER BY in DISTINCT must match the argument list" rule.
        $valueExpr = match ($driver) {
            'pgsql' => "{$ref}::text",
            default => $ref,
        };

        if ($driver === 'pgsql' && $def->distinct && $filterSql !== null) {
            return self::pgStringAgg($ref, $valueExpr, $sep, $orderBy, $distinctKw)
                ." FILTER (WHERE {$filterSql})";
        }

        if ($filterSql !== null) {
            $valueExpr = "CASE WHEN {$filterSql} THEN {$valueExpr} ELSE NULL END";
        }

        return match ($driver) {
            'pgsql' => self::pgStringAgg($ref, $valueExpr, $sep, $orderBy, $distinctKw),
            'mysql', 'mariadb' => self::mysqlStringAgg($valueExpr, $sep, $orderBy, $distinctKw),
            'sqlite' => self::sqliteStringAgg($valueExpr, $sep, $distinctKw),
            default => throw self::unsupportedDriver('stringAgg', $driver),
        };
    }

    private static function pgStringAgg(string $ref, string $valueExpr, string $sep, ?string $orderBy, string $distinctKw): string
    {
        // PG requires ORDER BY expressions in a DISTINCT aggregate to syntactically
        // match the argument list, so when distinct is on we cast the ORDER BY
        // column the same way as the aggregated value (::text). The attribute
        // guard forces orderBy == source under distinct, so casting is safe.
        $orderClause = '';
        if ($orderBy !== null) {
            $orderExpr = ($distinctKw !== '' && $orderBy === $ref) ? "{$orderBy}::text" : $orderBy;
            $orderClause = " ORDER BY {$orderExpr}";
        }

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

        // MySQL / MariaDB / SQLite: SQLite's JSON_GROUP_OBJECT skips rows where
        // the key is NULL, so wrapping the key in a guarded CASE filters them
        // out. MySQL/MariaDB error on a NULL key — the same wrapper does NOT
        // filter on those backends; users hitting a NULL key in the source
        // data on MySQL/MariaDB must avoid it themselves (push the IS NOT NULL
        // guard into their query, or use PostgreSQL). $allowNullKeys: true
        // plus a filter cannot be preserved on these backends either.
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
     * PostgreSQL native ordered-set aggregate for Median / Percentile.
     * PG supports `PERCENTILE_CONT(p) WITHIN GROUP (ORDER BY col)` with an
     * optional `FILTER (WHERE …)` clause.
     */
    public static function emitQuantileNativeExpression(
        AggregateDefinition $def,
        string $qualifier,
        ?string $filterSql = null,
    ): string {
        $source = self::requireSource($def);
        $p = number_format($def->percentilePoint, 10, '.', '');
        $expr = "PERCENTILE_CONT({$p}) WITHIN GROUP (ORDER BY {$qualifier}{$source})";

        if ($filterSql !== null) {
            $expr .= " FILTER (WHERE {$filterSql})";
        }

        return $expr;
    }

    /**
     * Window-function correlated subquery for Median / Percentile on
     * MySQL / MariaDB / SQLite, which lack ordered-set aggregates.
     *
     * Returns a complete `SELECT formula FROM (inner) _q` fragment suitable
     * for wrapping in parentheses as a scalar subquery.
     *
     * `$innerFromClause` is the `FROM … WHERE correlated-predicate` text
     * (already contains the scope + lft/rgt bounds). `$qualifier` is the
     * table alias prefix used inside that clause (`'d.'`, etc.).
     * `$filterSql` is an optional extra AND condition inside the derived table.
     *
     * Linear interpolation matches PERCENTILE_CONT semantics:
     *   result = (1 - frac) * val[low_rn] + frac * val[high_rn]
     * where frac = p * (N-1) - FLOOR(p * (N-1)), low_rn = FLOOR(p*(N-1))+1,
     * high_rn = CEIL(p*(N-1))+1 (1-indexed after ORDER BY).
     */
    public static function emitQuantileWindowSubquery(
        AggregateDefinition $def,
        string $innerFromClause,
        string $qualifier = 'd.',
        ?string $filterSql = null,
    ): string {
        $source = self::requireSource($def);
        $p = number_format($def->percentilePoint, 10, '.', '');
        $srcRef = $qualifier.$source;
        $filterClause = $filterSql !== null ? " AND ({$filterSql})" : '';
        // Exclude NULL source rows from the windowed set so ROW_NUMBER /
        // COUNT positions match PG PERCENTILE_CONT semantics (ordered-set
        // aggregates skip NULL inputs).
        $filterClause .= " AND ({$srcRef} IS NOT NULL)";

        $innerSelect =
            "SELECT {$srcRef} AS _src, "
            ."ROW_NUMBER() OVER (ORDER BY {$srcRef}) AS _rn, "
            .'COUNT(*) OVER () - 1 AS _cnt '
            .$innerFromClause.$filterClause;

        // coeff_low  = 1 - frac = 1 - (p*_cnt - FLOOR(p*_cnt))
        //            = 1 - p*_cnt + FLOOR(p*_cnt)   (constant 1.0 keeps numeric type)
        // coeff_high = frac = p*_cnt - FLOOR(p*_cnt)
        // low_rn  = FLOOR(p*_cnt) + 1
        // high_rn = CEIL(p*_cnt)  + 1
        $formula =
            "(1.0 - {$p} * MAX(_q._cnt) + FLOOR({$p} * MAX(_q._cnt)))"
            .' * MAX(CASE WHEN _q._rn = FLOOR('.$p.' * _q._cnt) + 1 THEN _q._src END)'
            ." + ({$p} * MAX(_q._cnt) - FLOOR({$p} * MAX(_q._cnt)))"
            .' * MAX(CASE WHEN _q._rn = CEIL('.$p.' * _q._cnt) + 1 THEN _q._src END)';

        return "SELECT {$formula} FROM ({$innerSelect}) _q";
    }

    /**
     * MariaDB Median / Percentile shape. MariaDB has no LATERAL and walls
     * off derived tables from the outer query's column scope, so the
     * window-function shape used on MySQL / SQLite (see
     * {@see emitQuantileWindowSubquery()}) is rejected with `Unknown
     * column 'x.lft' in 'WHERE'`. This emitter uses flat correlated
     * scalar subqueries — JSON_ARRAYAGG to materialise the sorted set,
     * JSON_VALUE to pick the two interpolation points — wrapped in a
     * bare `SELECT` that the caller turns into a scalar subquery.
     *
     * `$innerFromClause` and `$qualifier` have the same semantics as
     * {@see emitQuantileWindowSubquery()}; `$filterSql` is optionally
     * AND-ed into the inner WHERE alongside the NULL-source exclusion
     * that matches PG `PERCENTILE_CONT` semantics (JSON_ARRAYAGG would
     * otherwise emit `null` entries that pollute the ordering).
     *
     * The same correlated subquery shape is inlined four times for the
     * count (coefficients + two indices) and twice for the array (one
     * per interpolation point). MariaDB does not CSE these, so each
     * fires once per outer row — acceptable for a fresh-read-only
     * aggregate, documented here so future passes don't "optimise" it
     * back into a derived table that wouldn't survive the LATERAL gap.
     */
    public static function emitQuantileJsonExpression(
        AggregateDefinition $def,
        string $innerFromClause,
        string $qualifier = 'd.',
        ?string $filterSql = null,
    ): string {
        $source = self::requireSource($def);
        $p = number_format($def->percentilePoint, 10, '.', '');
        $srcRef = $qualifier.$source;
        $filterClause = $filterSql !== null ? " AND ({$filterSql})" : '';
        $notNullClause = " AND {$srcRef} IS NOT NULL";

        $cnt = "(SELECT JSON_LENGTH(JSON_ARRAYAGG({$srcRef})) {$innerFromClause}{$notNullClause}{$filterClause})";
        $arr = "(SELECT JSON_ARRAYAGG({$srcRef} ORDER BY {$srcRef}) {$innerFromClause}{$notNullClause}{$filterClause})";

        $pos = "{$p} * ({$cnt} - 1)";
        $valAt = static fn (string $idx): string => sprintf(
            "CAST(JSON_VALUE(%s, CONCAT('$[', %s, ']')) AS DECIMAL(30, 10))",
            $arr,
            $idx,
        );

        // Equivalent to (1 - frac) * v_low + frac * v_high with
        // frac = pos - FLOOR(pos), expanded so MariaDB sees pos in
        // the same arithmetic form each side.
        $formula =
            "(1.0 - {$pos} + FLOOR({$pos})) * ".$valAt("FLOOR({$pos})")
            ." + ({$pos} - FLOOR({$pos})) * ".$valAt("CEIL({$pos})");

        return "SELECT {$formula}";
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
            AggregateFunction::TopK => array_values(array_unique(array_filter([
                $def->source,
                $def->topKBy,
            ], static fn (?string $col): bool => $col !== null && $col !== ''))),
            default => $def->source !== null ? [$def->source] : [],
        };
    }

    /**
     * Build a correlated scalar subquery that returns the top-K JSON
     * array for the outer row's subtree — used by both the maintenance
     * recompute path and the fresh read path. Output is a JSON array of
     * `[source_value, by_value]` pairs, length up to `$def->k`, ordered
     * by the `by` column descending.
     *
     * `$boundsAndScope` is the AND-joined predicate that constrains the
     * inner subtree (lft/rgt bounds, scope joins, soft-delete, etc.).
     * `$filterSql` adds an extra row-level predicate when the aggregate
     * is filtered. Inner table is aliased `inner_a`; outer reference is
     * up to the caller.
     */
    public static function emitTopKCorrelatedSubquery(
        Connection $connection,
        AggregateDefinition $def,
        string $table,
        string $boundsAndScope,
        ?string $filterSql = null,
    ): string {
        if ($def->function !== AggregateFunction::TopK) {
            throw new AggregateConfigurationException(sprintf(
                'AggregateSqlEmitter::emitTopKCorrelatedSubquery(): expected TopK, got %s.',
                $def->function->value,
            ));
        }

        $source = self::requireSource($def);
        $by = $def->topKBy ?? throw new AggregateConfigurationException(sprintf(
            'TopK "%s" is missing its `by` column.',
            $def->column,
        ));
        $k = $def->k ?? throw new AggregateConfigurationException(sprintf(
            'TopK "%s" is missing its `k` value.',
            $def->column,
        ));

        $driver = $connection->getDriverName();

        $innerWhere = $boundsAndScope;
        // Rows with a NULL `by` value have no defined rank and must
        // never enter the Top-K list — every backend orders NULL
        // unpredictably and the result would become non-deterministic.
        $innerWhere .= " AND inner_a.{$by} IS NOT NULL";
        if ($filterSql !== null) {
            $innerWhere .= " AND ({$filterSql})";
        }

        $innerSelect = "SELECT inner_a.{$source} AS _src, inner_a.{$by} AS _by"
            ." FROM {$table} AS inner_a"
            ." WHERE {$innerWhere}"
            ." ORDER BY inner_a.{$by} DESC, inner_a.{$source} DESC"
            ." LIMIT {$k}";

        // JSON_AGG / JSON_ARRAYAGG / JSON_GROUP_ARRAY don't guarantee
        // input-row order on every backend, so re-apply the same
        // ordering inside the aggregator where supported.
        $jsonAgg = match ($driver) {
            'pgsql' => "JSON_AGG(JSON_BUILD_ARRAY(top._src, top._by) ORDER BY top._by DESC, top._src DESC)",
            'mysql', 'mariadb' => "JSON_ARRAYAGG(JSON_ARRAY(top._src, top._by))",
            'sqlite' => "JSON_GROUP_ARRAY(JSON_ARRAY(top._src, top._by))",
            default => throw self::unsupportedDriver('topK', $driver),
        };

        return "(SELECT {$jsonAgg} FROM ({$innerSelect}) top)";
    }

    /**
     * Leaf-row inline shape for TopK. A leaf's subtree is itself, so
     * the inclusive form yields a single-element JSON array; exclusive
     * yields NULL.
     */
    public static function leafTopK(Connection $connection, AggregateDefinition $def, string $tableQualifier, ?string $filterSql = null): string
    {
        if (! $def->inclusive) {
            return 'NULL';
        }

        $source = self::requireSource($def);
        $by = $def->topKBy ?? throw new AggregateConfigurationException(sprintf(
            'TopK "%s" is missing its `by` column.',
            $def->column,
        ));

        $srcRef = $tableQualifier.$source;
        $byRef = $tableQualifier.$by;

        $driver = $connection->getDriverName();
        $singleElement = match ($driver) {
            'pgsql' => "JSON_BUILD_ARRAY(JSON_BUILD_ARRAY({$srcRef}, {$byRef}))",
            'mysql', 'mariadb', 'sqlite' => "JSON_ARRAY(JSON_ARRAY({$srcRef}, {$byRef}))",
            default => throw self::unsupportedDriver('topK leaf inline', $driver),
        };

        // NULL `by` excludes the row from the result. A filtered leaf
        // that doesn't pass the predicate also yields NULL.
        $guard = "{$byRef} IS NOT NULL";
        if ($filterSql !== null) {
            $guard .= " AND ({$filterSql})";
        }

        return "CASE WHEN {$guard} THEN {$singleElement} ELSE NULL END";
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
