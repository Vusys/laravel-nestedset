<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\NestedSetServiceProvider;

/**
 * Resolving a connection must not open it: `artisan package:discover`, run by
 * every `composer install`, can resolve one while booting wherever the
 * database doesn't exist yet (CI, image builds). The SQLite bitwise
 * aggregates are installed just before the first query instead (#260).
 */
final class BootWithoutDatabaseTest extends OrchestraTestCase
{
    #[\Override]
    protected function getPackageProviders($app): array
    {
        return [NestedSetServiceProvider::class];
    }

    #[\Override]
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'missing');
        $app['config']->set('database.connections.missing', [
            'driver' => 'sqlite',
            'database' => '/nonexistent/database.sqlite',
        ]);
    }

    #[Test]
    public function resolving_a_connection_does_not_open_it(): void
    {
        DB::connection();

        $this->assertSame(['missing'], array_keys(DB::getConnections()));
    }

    #[Test]
    public function the_bitwise_aggregates_are_installed_before_the_first_query(): void
    {
        config(['database.connections.memory' => ['driver' => 'sqlite', 'database' => ':memory:']]);

        $value = DB::connection('memory')->scalar('select bit_or(x) from (select 1 as x union all select 4)');

        $this->assertEquals(5, $value);
    }
}
