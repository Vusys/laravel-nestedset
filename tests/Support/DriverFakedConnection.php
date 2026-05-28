<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\SQLiteConnection;

/**
 * Delegating connection that reports a synthetic driver name. Wraps a
 * real SQLite connection so PDO::quote keeps working for string
 * literals; only the backend-dispatch checks in the SQL builders see
 * the spoofed driver. Shared by the per-driver SQL snapshot tests.
 */
final class DriverFakedConnection extends SQLiteConnection
{
    public function __construct(
        private readonly Connection $delegate,
        private readonly string $fakedDriver,
    ) {
        // We never call parent::__construct — every override below
        // forwards to the real delegate.
    }

    #[\Override]
    public function getDriverName(): string
    {
        return $this->fakedDriver;
    }

    #[\Override]
    public function getPdo(): \PDO
    {
        return $this->delegate->getPdo();
    }
}
