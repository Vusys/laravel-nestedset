<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Aggregates;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\SQLiteConnection;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\Sql\AggregateSqlEmitter;

/**
 * Snapshot tests for the per-driver SQL emitter. The connection is a
 * real in-memory SQLite, but we wrap it in {@see DriverFakedConnection}
 * to report alternative driver names — PDO quoting still goes through
 * the real driver, which is fine because the only string we quote here
 * is the user-supplied separator and JSON keys.
 */
final class AggregateSqlEmitterTest extends TestCase
{
    private Connection $sqliteConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->sqliteConnection = $capsule->getConnection();
    }

    private function fakeDriver(string $driver): Connection
    {
        if ($driver === 'sqlite') {
            return $this->sqliteConnection;
        }

        return new DriverFakedConnection($this->sqliteConnection, $driver);
    }

    public function test_distinct_count_is_universal(): void
    {
        $def = Aggregate::distinctCount('owner_id')->into('distinct_owners');

        foreach (['sqlite', 'mysql', 'mariadb', 'pgsql'] as $driver) {
            $sql = AggregateSqlEmitter::emit($this->fakeDriver($driver), $def, 'i.');
            $this->assertSame('COUNT(DISTINCT i.owner_id)', $sql, "driver={$driver}");
        }
    }

    public function test_distinct_count_with_filter_uses_case_when(): void
    {
        $def = Aggregate::distinctCount('owner_id')->into('distinct_owners');
        $sql = AggregateSqlEmitter::emit($this->sqliteConnection, $def, 'i.', 'i.active = 1');

        $this->assertSame(
            'COUNT(DISTINCT CASE WHEN i.active = 1 THEN i.owner_id ELSE NULL END)',
            $sql,
        );
    }

    public function test_pg_string_agg_casts_source_to_text_and_includes_order_by(): void
    {
        $def = Aggregate::stringAgg('name')->into('child_names');
        $sql = AggregateSqlEmitter::emit($this->fakeDriver('pgsql'), $def, 'i.');

        $this->assertSame(
            "STRING_AGG(i.name::text, ', ' ORDER BY i.name)",
            $sql,
        );
    }

    public function test_mysql_string_agg_uses_separator_keyword(): void
    {
        $def = Aggregate::stringAgg('name', separator: '; ')->into('child_names');
        $sql = AggregateSqlEmitter::emit($this->fakeDriver('mysql'), $def, 'i.');

        $this->assertSame(
            "GROUP_CONCAT(i.name ORDER BY i.name SEPARATOR '; ')",
            $sql,
        );
    }

    public function test_mariadb_string_agg_uses_separator_keyword(): void
    {
        $def = Aggregate::stringAgg('name')->into('child_names');
        $sql = AggregateSqlEmitter::emit($this->fakeDriver('mariadb'), $def, 'i.');

        $this->assertSame(
            "GROUP_CONCAT(i.name ORDER BY i.name SEPARATOR ', ')",
            $sql,
        );
    }

    public function test_sqlite_string_agg_uses_two_argument_form(): void
    {
        $def = Aggregate::stringAgg('name', separator: '; ')->into('child_names');
        $sql = AggregateSqlEmitter::emit($this->sqliteConnection, $def, 'i.');

        $this->assertSame(
            "GROUP_CONCAT(i.name, '; ')",
            $sql,
        );
    }

    public function test_pg_string_agg_distinct_casts_order_clause(): void
    {
        $def = Aggregate::stringAgg('tag')->distinct()->into('distinct_tags');
        $sql = AggregateSqlEmitter::emit($this->fakeDriver('pgsql'), $def, 'i.');

        $this->assertSame(
            "STRING_AGG(DISTINCT i.tag::text, ', ' ORDER BY i.tag::text)",
            $sql,
        );
    }

    public function test_mysql_string_agg_distinct_includes_order_by(): void
    {
        $def = Aggregate::stringAgg('tag')->distinct()->into('distinct_tags');
        $sql = AggregateSqlEmitter::emit($this->fakeDriver('mysql'), $def, 'i.');

        $this->assertSame(
            "GROUP_CONCAT(DISTINCT i.tag ORDER BY i.tag SEPARATOR ', ')",
            $sql,
        );
    }

    public function test_sqlite_string_agg_distinct_loses_custom_separator(): void
    {
        // Documented caveat: SQLite GROUP_CONCAT(DISTINCT ...) does not
        // accept a separator argument. The emitter falls back to the
        // default comma-space separator.
        $def = Aggregate::stringAgg('tag', separator: ' | ')->distinct()->into('distinct_tags');
        $sql = AggregateSqlEmitter::emit($this->sqliteConnection, $def, 'i.');

        $this->assertSame('GROUP_CONCAT(DISTINCT i.tag)', $sql);
    }

    public function test_pg_json_agg_scalar_with_order_by(): void
    {
        $def = Aggregate::jsonAgg('id')->into('descendant_ids');
        $sql = AggregateSqlEmitter::emit($this->fakeDriver('pgsql'), $def, 'i.');

        $this->assertSame('JSON_AGG(i.id ORDER BY i.id)', $sql);
    }

    public function test_mysql_json_agg_scalar(): void
    {
        $def = Aggregate::jsonAgg('id')->into('descendant_ids');
        $sql = AggregateSqlEmitter::emit($this->fakeDriver('mysql'), $def, 'i.');

        $this->assertSame('JSON_ARRAYAGG(i.id)', $sql);
    }

    public function test_sqlite_json_agg_scalar(): void
    {
        $def = Aggregate::jsonAgg('id')->into('descendant_ids');
        $sql = AggregateSqlEmitter::emit($this->sqliteConnection, $def, 'i.');

        $this->assertSame('JSON_GROUP_ARRAY(i.id)', $sql);
    }

    public function test_pg_json_agg_multi_column_uses_build_object(): void
    {
        $def = Aggregate::jsonAgg(['id' => 'id', 'label' => 'name'])
            ->into('descendant_summary');
        $sql = AggregateSqlEmitter::emit($this->fakeDriver('pgsql'), $def, 'i.');

        $this->assertSame(
            "JSON_AGG(JSON_BUILD_OBJECT('id', i.id, 'label', i.name))",
            $sql,
        );
    }

    public function test_mysql_json_agg_multi_column_uses_json_object(): void
    {
        $def = Aggregate::jsonAgg(['id' => 'id', 'label' => 'name'])
            ->into('descendant_summary');
        $sql = AggregateSqlEmitter::emit($this->fakeDriver('mysql'), $def, 'i.');

        $this->assertSame(
            "JSON_ARRAYAGG(JSON_OBJECT('id', i.id, 'label', i.name))",
            $sql,
        );
    }

    public function test_pg_json_object_agg_casts_key_to_text_and_filters_null_keys(): void
    {
        $def = Aggregate::jsonObjectAgg(key: 'slug', value: 'name')->into('slug_to_name');
        $sql = AggregateSqlEmitter::emit($this->fakeDriver('pgsql'), $def, 'i.');

        $this->assertSame(
            'JSON_OBJECT_AGG(i.slug::text, i.name ORDER BY i.slug) FILTER (WHERE i.slug IS NOT NULL)',
            $sql,
        );
    }

    public function test_pg_json_object_agg_allow_null_keys_omits_filter(): void
    {
        $def = Aggregate::jsonObjectAgg(key: 'slug', value: 'name', allowNullKeys: true)
            ->into('slug_to_name');
        $sql = AggregateSqlEmitter::emit($this->fakeDriver('pgsql'), $def, 'i.');

        $this->assertSame(
            'JSON_OBJECT_AGG(i.slug::text, i.name ORDER BY i.slug)',
            $sql,
        );
    }

    public function test_mysql_json_object_agg_emits_null_key_for_null_value(): void
    {
        $def = Aggregate::jsonObjectAgg(key: 'slug', value: 'name')->into('slug_to_name');
        $sql = AggregateSqlEmitter::emit($this->fakeDriver('mysql'), $def, 'i.');

        $this->assertSame(
            'JSON_OBJECTAGG(CASE WHEN i.slug IS NOT NULL THEN i.slug ELSE NULL END, i.name)',
            $sql,
        );
    }

    public function test_sqlite_json_object_agg_uses_group_object_form(): void
    {
        $def = Aggregate::jsonObjectAgg(key: 'slug', value: 'name')->into('slug_to_name');
        $sql = AggregateSqlEmitter::emit($this->sqliteConnection, $def, 'i.');

        $this->assertSame(
            'JSON_GROUP_OBJECT(CASE WHEN i.slug IS NOT NULL THEN i.slug ELSE NULL END, i.name)',
            $sql,
        );
    }

    public function test_pg_string_agg_filter_uses_case_when(): void
    {
        $def = Aggregate::stringAgg('name')->into('names');
        $sql = AggregateSqlEmitter::emit(
            $this->fakeDriver('pgsql'),
            $def,
            'i.',
            'i.published = 1',
        );

        $this->assertSame(
            "STRING_AGG(CASE WHEN i.published = 1 THEN i.name::text ELSE NULL END, ', ' ORDER BY i.name)",
            $sql,
        );
    }

    public function test_pg_string_agg_distinct_with_filter_uses_filter_clause(): void
    {
        // PG rejects DISTINCT aggregates whose ORDER BY expressions don't appear
        // in the argument list. Wrapping the value in CASE breaks that rule —
        // the FILTER clause keeps the argument simple and identical to ORDER BY.
        $def = Aggregate::stringAgg('tag')->distinct()->into('distinct_tags');
        $sql = AggregateSqlEmitter::emit(
            $this->fakeDriver('pgsql'),
            $def,
            'i.',
            'i.published = 1',
        );

        $this->assertSame(
            "STRING_AGG(DISTINCT i.tag::text, ', ' ORDER BY i.tag::text) FILTER (WHERE i.published = 1)",
            $sql,
        );
    }

    public function test_pg_json_agg_filter_uses_filter_clause(): void
    {
        $def = Aggregate::jsonAgg('id')->into('ids');
        $sql = AggregateSqlEmitter::emit(
            $this->fakeDriver('pgsql'),
            $def,
            'i.',
            'i.published = 1',
        );

        $this->assertSame(
            'JSON_AGG(i.id ORDER BY i.id) FILTER (WHERE i.published = 1)',
            $sql,
        );
    }

    public function test_leaf_distinct_count_inclusive_returns_one_for_non_null(): void
    {
        $def = Aggregate::distinctCount('owner_id')->into('distinct_owners');
        $sql = AggregateSqlEmitter::leafInline($this->sqliteConnection, $def, 't.');

        $this->assertSame('CASE WHEN t.owner_id IS NULL THEN 0 ELSE 1 END', $sql);
    }

    public function test_leaf_distinct_count_exclusive_returns_zero(): void
    {
        $def = Aggregate::distinctCount('owner_id')->exclusive()->into('distinct_owners');
        $sql = AggregateSqlEmitter::leafInline($this->sqliteConnection, $def, 't.');

        $this->assertSame('0', $sql);
    }

    public function test_leaf_string_agg_exclusive_returns_null(): void
    {
        $def = Aggregate::stringAgg('name')->exclusive()->into('child_names');
        $sql = AggregateSqlEmitter::leafInline($this->sqliteConnection, $def, 't.');

        $this->assertSame('NULL', $sql);
    }

    public function test_leaf_json_agg_inclusive_pg(): void
    {
        $def = Aggregate::jsonAgg('id')->into('ids');
        $sql = AggregateSqlEmitter::leafInline($this->fakeDriver('pgsql'), $def, 't.');

        $this->assertSame('JSON_BUILD_ARRAY(t.id)', $sql);
    }

    public function test_leaf_json_agg_inclusive_mysql(): void
    {
        $def = Aggregate::jsonAgg('id')->into('ids');
        $sql = AggregateSqlEmitter::leafInline($this->fakeDriver('mysql'), $def, 't.');

        $this->assertSame('JSON_ARRAY(t.id)', $sql);
    }

    public function test_leaf_json_object_agg_inclusive_pg_with_null_guard(): void
    {
        $def = Aggregate::jsonObjectAgg(key: 'slug', value: 'name')->into('lookup');
        $sql = AggregateSqlEmitter::leafInline($this->fakeDriver('pgsql'), $def, 't.');

        $this->assertSame(
            'CASE WHEN t.slug IS NOT NULL THEN JSON_BUILD_OBJECT(t.slug::text, t.name) ELSE NULL END',
            $sql,
        );
    }

    public function test_watch_columns_returns_source(): void
    {
        $def = Aggregate::stringAgg('name')->into('child_names');
        $this->assertSame(['name'], AggregateSqlEmitter::watchColumns($def));
    }

    public function test_watch_columns_returns_multi_column_sources(): void
    {
        $def = Aggregate::jsonAgg(['id' => 'id', 'label' => 'name'])
            ->into('descendant_summary');
        $this->assertSame(['id', 'name'], AggregateSqlEmitter::watchColumns($def));
    }

    public function test_watch_columns_returns_key_and_value_for_json_object_agg(): void
    {
        $def = Aggregate::jsonObjectAgg(key: 'slug', value: 'name')->into('lookup');
        $this->assertSame(['slug', 'name'], AggregateSqlEmitter::watchColumns($def));
    }
}

/**
 * Delegating connection that reports a synthetic driver name. Wraps a
 * real SQLite connection so PDO::quote keeps working for string
 * literals; only the few backend-dispatch checks in
 * {@see AggregateSqlEmitter} see the spoofed driver.
 *
 * @internal scope: this test file only
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
