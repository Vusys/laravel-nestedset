<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates;

use Illuminate\Database\Connection;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;

/**
 * Quotes filter values for inline embedding in generated aggregate SQL.
 *
 * Filter equality conditions render as `col = <literal>` inside CASE WHEN
 * fragments and correlated subqueries — and those fragments are
 * concatenated into a larger SQL string rather than flowing through
 * Eloquent's bound-parameter list (the surrounding shape is a TreeExpression
 * select, which is opaque to the builder's binding stream).
 *
 * String values must therefore be escaped against the actual driver's
 * literal syntax. PDO::quote knows the driver's escape rules: MySQL's
 * default mode treats backslash as an escape character, so the previous
 * naive `str_replace("'", "''", $value)` would silently rewrite
 * `'foo\bar'` to `'foobar'` on MySQL/MariaDB. PDO::quote handles
 * backslashes correctly for every supported driver.
 *
 * The package's existing security boundary — "trusted constants only,
 * never user-supplied input" — still applies; this class strengthens
 * correctness, not the threat model.
 */
final class FilterValueQuoter
{
    public static function quote(Connection $connection, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            // TRUE / FALSE work across PostgreSQL, MySQL/MariaDB,
            // and SQLite — they're the only inline boolean literal
            // syntax PostgreSQL accepts against a real BOOLEAN
            // column. `1` / `0` fails there.
            return $value ? 'TRUE' : 'FALSE';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            throw new AggregateConfigurationException(sprintf(
                'FilterPredicate equality condition value must be scalar; got %s.',
                get_debug_type($value),
            ));
        }

        $quoted = $connection->getPdo()->quote($value);

        if ($quoted === false) {
            throw new AggregateConfigurationException(sprintf(
                'PDO::quote returned false for filter value on driver %s — '
                .'the driver does not support inline quoting and the value '
                .'cannot be safely embedded.',
                $connection->getDriverName(),
            ));
        }

        return $quoted;
    }
}
