<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use stdClass;
use Vusys\NestedSet\NestedSetServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * Fetch a row by id and fail the test if it's missing.
     *
     * Why: PHPStan widens `DB::first()` to `stdClass|null` and refuses to let
     * tests dereference fields without proving non-null. `fail()` is `never`,
     * so this narrows correctly without `assert()` or type casts.
     */
    protected function rowById(string $table, int $id): stdClass
    {
        $row = DB::table($table)->where('id', $id)->first();

        if ($row === null) {
            $this->fail("Row {$id} not found in {$table}");
        }

        return $row;
    }

    protected function defineEnvironment($app): void
    {
        $connection = env('DB_CONNECTION', 'sqlite');

        $app['config']->set('database.default', $connection);

        $app['config']->set("database.connections.{$connection}", match ($connection) {
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '5432'),
                'database' => env('DB_DATABASE', 'testing'),
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => env('DB_PASSWORD', 'password'),
                'charset' => 'utf8',
            ],
            'sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
            ],
            default => [
                // Covers both mysql and mariadb — Laravel uses the mysql driver for both.
                'driver' => 'mysql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '3306'),
                'database' => env('DB_DATABASE', 'testing'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', 'password'),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ],
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/Migrations');
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [NestedSetServiceProvider::class];
    }
}
