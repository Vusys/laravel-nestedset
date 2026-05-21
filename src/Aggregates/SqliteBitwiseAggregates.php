<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates;

use Illuminate\Database\Connection;
use PDO;
use WeakMap;

/**
 * Registers BIT_OR / BIT_AND / BIT_XOR user-defined aggregates on a
 * SQLite PDO connection so the same native-aggregate SQL the package
 * emits for MySQL / MariaDB / PostgreSQL also runs on SQLite.
 *
 * SQLite ships bitwise operators (`&`, `|`, `^`, `~`, `<<`, `>>`) but
 * no aggregate functions for them. `PDO::sqliteCreateAggregate()`
 * extends the connection with three folds that NULL-skip in the same
 * way the native aggregates on the other backends do — empty inputs
 * return NULL, otherwise NULL contributions are ignored.
 *
 * Idempotent per-PDO: we track installed connections in a WeakMap so
 * a connection that gets re-checked in a tight loop only pays the
 * registration cost once.
 */
final class SqliteBitwiseAggregates
{
    /**
     * @var WeakMap<PDO, true>|null
     */
    private static ?WeakMap $installed = null;

    /**
     * Installs the three bitwise aggregate UDAs on the given
     * connection's PDO if not already installed. No-op on non-SQLite
     * drivers.
     */
    public static function ensureInstalled(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'sqlite') {
            return;
        }

        $pdo = $connection->getPdo();

        if (! self::$installed instanceof WeakMap) {
            self::$installed = new WeakMap;
        }

        if (isset(self::$installed[$pdo])) {
            return;
        }

        // SQLite UDA step signature: (mixed $context, int $rownumber, mixed $value): mixed.
        // The returned value becomes $context for the next call. The
        // finalize callback receives the final $context plus the row
        // count; we discard the row count and return the accumulator
        // (or NULL when no non-null rows contributed).

        $pdo->sqliteCreateAggregate(
            'BIT_OR',
            self::stepFor(static fn (int $acc, int $value): int => $acc | $value),
            self::finalizer(),
        );

        $pdo->sqliteCreateAggregate(
            'BIT_AND',
            self::stepFor(static fn (int $acc, int $value): int => $acc & $value),
            self::finalizer(),
        );

        $pdo->sqliteCreateAggregate(
            'BIT_XOR',
            self::stepFor(static fn (int $acc, int $value): int => $acc ^ $value),
            self::finalizer(),
        );

        self::$installed[$pdo] = true;
    }

    /**
     * Builds a SQLite UDA step callable that ignores NULL contributions
     * and applies $combine to the running accumulator for every
     * non-null input. The first non-null input seeds the accumulator;
     * subsequent ones are combined with the existing seed.
     *
     * Context shape: `array{seen: bool, value: int}` for "have we
     * accepted a non-null contribution yet?".
     *
     * @param  callable(int, int): int  $combine
     * @return callable(mixed, int, mixed): array{seen: bool, value: int}
     */
    private static function stepFor(callable $combine): callable
    {
        return static function (mixed $context, int $rowNumber, mixed $value) use ($combine): array {
            /** @var array{seen: bool, value: int} $acc */
            $acc = is_array($context)
                ? $context
                : ['seen' => false, 'value' => 0];

            if ($value === null) {
                return $acc;
            }

            $intValue = is_int($value) ? $value : (int) (is_numeric($value) ? $value : 0);

            if (! $acc['seen']) {
                return ['seen' => true, 'value' => $intValue];
            }

            return ['seen' => true, 'value' => $combine($acc['value'], $intValue)];
        };
    }

    /**
     * @return callable(mixed, int): (int|null)
     */
    private static function finalizer(): callable
    {
        return static function (mixed $context, int $rowCount): ?int {
            if (! is_array($context)) {
                return null;
            }

            /** @var array{seen: bool, value: int} $context */
            return $context['seen'] ? $context['value'] : null;
        };
    }
}
