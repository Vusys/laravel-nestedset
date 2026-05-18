<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use stdClass;
use Vusys\NestedSet\NestedSetServiceProvider;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;

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

    /**
     * Opt out of the tearDown tree-integrity check for tests that
     * intentionally leave the tree corrupt (repair tests, force-delete
     * orphan tests, etc.).
     */
    protected bool $allowBrokenTreeAtTearDown = false;

    /**
     * Persistent backends (MySQL / MariaDB / PostgreSQL) keep the
     * connection across tests in the same class instance — without a
     * RefreshDatabase trait, rows pile up. SQLite is per-test-connection
     * fresh so the issue is invisible there. Truncate the fixture tables
     * at the start of every test so each one starts from a clean slate
     * regardless of backend.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $tables = ['areas', 'branches', 'categories', 'menu_items', 'menus', 'typed_areas', 'monsters'];

        foreach ($tables as $table) {
            if (DB::connection()->getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->delete();
            }
        }
    }

    /**
     * Raw bulk inserts with explicit `id` values leave PostgreSQL's
     * SEQUENCE untouched — subsequent Eloquent INSERTs pull id=1 and
     * collide with the seeded rows. MySQL/MariaDB auto-increment
     * silently advances past explicit ids, and SQLite uses ROWID so
     * the issue doesn't arise. PG needs an explicit `setval`.
     *
     * Call this after a setUp that seeds rows with explicit ids and
     * before any test code that creates rows through Eloquent.
     */
    protected function syncSequence(string $table): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $rawMax = DB::table($table)->max('id');
        $maxId = is_numeric($rawMax) ? (int) $rawMax : 0;

        if ($maxId === 0) {
            return;
        }

        $connection->statement(
            "SELECT setval(pg_get_serial_sequence(?, 'id'), ?)",
            [$table, $maxId],
        );
    }

    /**
     * Hardening: every test ends with a tree-integrity check on each
     * nested-set fixture. Catches regressions where a mutation looks
     * locally correct in an assertion but leaves the tree corrupt.
     */
    protected function tearDown(): void
    {
        // Skip the postcondition when the test already failed (the broken
        // tree is likely a consequence of the actual failure, not the cause
        // — and the fail() call inside tearDown would mask the real error).
        $alreadyFailed = ! $this->status()->isUnknown() && ! $this->status()->isSuccess();

        if (! $this->allowBrokenTreeAtTearDown && ! $alreadyFailed) {
            $this->assertCategoriesTreeIntact();
            $this->assertMenuItemsTreesIntact();
        }

        parent::tearDown();
    }

    private function assertCategoriesTreeIntact(): void
    {
        if (! DB::connection()->getSchemaBuilder()->hasTable('categories')) {
            return;
        }

        $errors = Category::countErrors();
        $total = array_sum($errors);

        if ($total > 0) {
            $this->fail('Categories tree is broken at tearDown: '.json_encode($errors));
        }
    }

    private function assertMenuItemsTreesIntact(): void
    {
        if (! DB::connection()->getSchemaBuilder()->hasTable('menu_items')) {
            return;
        }

        /** @var array<int, mixed> $menuIds */
        $menuIds = DB::table('menu_items')->distinct()->pluck('menu_id')->all();

        foreach ($menuIds as $menuId) {
            if (! is_numeric($menuId)) {
                continue;
            }

            $anchor = MenuItem::query()->where('menu_id', (int) $menuId)->first();

            if ($anchor === null) {
                continue;
            }

            $errors = MenuItem::countErrors($anchor);
            $total = array_sum($errors);

            if ($total > 0) {
                $this->fail("MenuItems tree for menu {$menuId} is broken at tearDown: ".json_encode($errors));
            }
        }
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
