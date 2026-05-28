<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Aggregates\Sql;

use Closure;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Sql\AggregateSqlEmitter;
use Vusys\NestedSet\Tests\Support\DriverFakedConnection;

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

    /**
     * Subtree-aggregate SQL fragment per driver, with an optional
     * pre-built filter predicate.
     *
     * @param  Closure(): AggregateDefinition  $makeDefinition
     */
    #[DataProvider('emitCases')]
    public function test_emit(string $driver, Closure $makeDefinition, ?string $filterSql, string $expected): void
    {
        $sql = AggregateSqlEmitter::emit($this->fakeDriver($driver), $makeDefinition(), 'i.', $filterSql);

        $this->assertSame($expected, $sql);
    }

    /**
     * @return iterable<string, array{0: string, 1: Closure(): AggregateDefinition, 2: ?string, 3: string}>
     */
    public static function emitCases(): iterable
    {
        $distinctCount = static fn (): AggregateDefinition => Aggregate::distinctCount('owner_id')->into('distinct_owners');

        // DISTINCT COUNT emits identically on every backend.
        foreach (['sqlite', 'mysql', 'mariadb', 'pgsql'] as $driver) {
            yield "distinct_count is universal on {$driver}" => [$driver, $distinctCount, null, 'COUNT(DISTINCT i.owner_id)'];
        }

        yield 'distinct_count with filter uses CASE WHEN' => [
            'sqlite', $distinctCount, 'i.active = 1',
            'COUNT(DISTINCT CASE WHEN i.active = 1 THEN i.owner_id ELSE NULL END)',
        ];

        yield 'pg string_agg casts source to text and orders' => [
            'pgsql', static fn (): AggregateDefinition => Aggregate::stringAgg('name')->into('child_names'), null,
            "STRING_AGG(i.name::text, ', ' ORDER BY i.name)",
        ];

        yield 'mysql string_agg uses SEPARATOR keyword' => [
            'mysql', static fn (): AggregateDefinition => Aggregate::stringAgg('name', separator: '; ')->into('child_names'), null,
            "GROUP_CONCAT(i.name ORDER BY i.name SEPARATOR '; ')",
        ];

        yield 'mariadb string_agg uses SEPARATOR keyword' => [
            'mariadb', static fn (): AggregateDefinition => Aggregate::stringAgg('name')->into('child_names'), null,
            "GROUP_CONCAT(i.name ORDER BY i.name SEPARATOR ', ')",
        ];

        yield 'sqlite string_agg uses two-argument form' => [
            'sqlite', static fn (): AggregateDefinition => Aggregate::stringAgg('name', separator: '; ')->into('child_names'), null,
            "GROUP_CONCAT(i.name, '; ')",
        ];

        yield 'pg string_agg distinct casts the order clause' => [
            'pgsql', static fn (): AggregateDefinition => Aggregate::stringAgg('tag')->distinct()->into('distinct_tags'), null,
            "STRING_AGG(DISTINCT i.tag::text, ', ' ORDER BY i.tag::text)",
        ];

        yield 'mysql string_agg distinct includes ORDER BY' => [
            'mysql', static fn (): AggregateDefinition => Aggregate::stringAgg('tag')->distinct()->into('distinct_tags'), null,
            "GROUP_CONCAT(DISTINCT i.tag ORDER BY i.tag SEPARATOR ', ')",
        ];

        // Documented caveat: SQLite GROUP_CONCAT(DISTINCT ...) does not
        // accept a separator argument — the emitter falls back to the
        // default comma-space separator.
        yield 'sqlite string_agg distinct loses the custom separator' => [
            'sqlite', static fn (): AggregateDefinition => Aggregate::stringAgg('tag', separator: ' | ')->distinct()->into('distinct_tags'), null,
            'GROUP_CONCAT(DISTINCT i.tag)',
        ];

        yield 'pg json_agg scalar with ORDER BY' => [
            'pgsql', static fn (): AggregateDefinition => Aggregate::jsonAgg('id')->into('descendant_ids'), null,
            'JSON_AGG(i.id ORDER BY i.id)',
        ];

        yield 'mysql json_agg scalar' => [
            'mysql', static fn (): AggregateDefinition => Aggregate::jsonAgg('id')->into('descendant_ids'), null,
            'JSON_ARRAYAGG(i.id)',
        ];

        yield 'sqlite json_agg scalar' => [
            'sqlite', static fn (): AggregateDefinition => Aggregate::jsonAgg('id')->into('descendant_ids'), null,
            'JSON_GROUP_ARRAY(i.id)',
        ];

        yield 'pg json_agg multi-column uses BUILD_OBJECT' => [
            'pgsql', static fn (): AggregateDefinition => Aggregate::jsonAgg(['id' => 'id', 'label' => 'name'])->into('descendant_summary'), null,
            "JSON_AGG(JSON_BUILD_OBJECT('id', i.id, 'label', i.name))",
        ];

        yield 'mysql json_agg multi-column uses JSON_OBJECT' => [
            'mysql', static fn (): AggregateDefinition => Aggregate::jsonAgg(['id' => 'id', 'label' => 'name'])->into('descendant_summary'), null,
            "JSON_ARRAYAGG(JSON_OBJECT('id', i.id, 'label', i.name))",
        ];

        yield 'pg json_object_agg casts key to text and filters null keys' => [
            'pgsql', static fn (): AggregateDefinition => Aggregate::jsonObjectAgg(key: 'slug', value: 'name')->into('slug_to_name'), null,
            'JSON_OBJECT_AGG(i.slug::text, i.name ORDER BY i.slug) FILTER (WHERE i.slug IS NOT NULL)',
        ];

        yield 'pg json_object_agg with allowNullKeys omits the filter' => [
            'pgsql', static fn (): AggregateDefinition => Aggregate::jsonObjectAgg(key: 'slug', value: 'name', allowNullKeys: true)->into('slug_to_name'), null,
            'JSON_OBJECT_AGG(i.slug::text, i.name ORDER BY i.slug)',
        ];

        yield 'mysql json_object_agg guards null keys via CASE' => [
            'mysql', static fn (): AggregateDefinition => Aggregate::jsonObjectAgg(key: 'slug', value: 'name')->into('slug_to_name'), null,
            'JSON_OBJECTAGG(CASE WHEN i.slug IS NOT NULL THEN i.slug ELSE NULL END, i.name)',
        ];

        yield 'sqlite json_object_agg uses GROUP_OBJECT form' => [
            'sqlite', static fn (): AggregateDefinition => Aggregate::jsonObjectAgg(key: 'slug', value: 'name')->into('slug_to_name'), null,
            'JSON_GROUP_OBJECT(CASE WHEN i.slug IS NOT NULL THEN i.slug ELSE NULL END, i.name)',
        ];

        yield 'pg string_agg filter uses CASE WHEN' => [
            'pgsql', static fn (): AggregateDefinition => Aggregate::stringAgg('name')->into('names'), 'i.published = 1',
            "STRING_AGG(CASE WHEN i.published = 1 THEN i.name::text ELSE NULL END, ', ' ORDER BY i.name)",
        ];

        // PG rejects DISTINCT aggregates whose ORDER BY expressions don't appear
        // in the argument list. Wrapping the value in CASE breaks that rule — the
        // FILTER clause keeps the argument simple and identical to ORDER BY.
        yield 'pg string_agg distinct with filter uses FILTER clause' => [
            'pgsql', static fn (): AggregateDefinition => Aggregate::stringAgg('tag')->distinct()->into('distinct_tags'), 'i.published = 1',
            "STRING_AGG(DISTINCT i.tag::text, ', ' ORDER BY i.tag::text) FILTER (WHERE i.published = 1)",
        ];

        yield 'pg json_agg filter uses FILTER clause' => [
            'pgsql', static fn (): AggregateDefinition => Aggregate::jsonAgg('id')->into('ids'), 'i.published = 1',
            'JSON_AGG(i.id ORDER BY i.id) FILTER (WHERE i.published = 1)',
        ];
    }

    /**
     * Leaf fast-path inline value (a single-row subtree) per driver.
     *
     * @param  Closure(): AggregateDefinition  $makeDefinition
     */
    #[DataProvider('leafInlineCases')]
    public function test_leaf_inline(string $driver, Closure $makeDefinition, string $expected): void
    {
        $sql = AggregateSqlEmitter::leafInline($this->fakeDriver($driver), $makeDefinition(), 't.');

        $this->assertSame($expected, $sql);
    }

    /**
     * @return iterable<string, array{0: string, 1: Closure(): AggregateDefinition, 2: string}>
     */
    public static function leafInlineCases(): iterable
    {
        yield 'distinct_count inclusive returns 1 for non-null' => [
            'sqlite', static fn (): AggregateDefinition => Aggregate::distinctCount('owner_id')->into('distinct_owners'),
            'CASE WHEN t.owner_id IS NULL THEN 0 ELSE 1 END',
        ];

        yield 'distinct_count exclusive returns 0' => [
            'sqlite', static fn (): AggregateDefinition => Aggregate::distinctCount('owner_id')->exclusive()->into('distinct_owners'),
            '0',
        ];

        yield 'string_agg exclusive returns NULL' => [
            'sqlite', static fn (): AggregateDefinition => Aggregate::stringAgg('name')->exclusive()->into('child_names'),
            'NULL',
        ];

        yield 'json_agg inclusive on pg' => [
            'pgsql', static fn (): AggregateDefinition => Aggregate::jsonAgg('id')->into('ids'),
            'JSON_BUILD_ARRAY(t.id)',
        ];

        yield 'json_agg inclusive on mysql' => [
            'mysql', static fn (): AggregateDefinition => Aggregate::jsonAgg('id')->into('ids'),
            'JSON_ARRAY(t.id)',
        ];

        yield 'json_object_agg inclusive on pg with null guard' => [
            'pgsql', static fn (): AggregateDefinition => Aggregate::jsonObjectAgg(key: 'slug', value: 'name')->into('lookup'),
            'CASE WHEN t.slug IS NOT NULL THEN JSON_BUILD_OBJECT(t.slug::text, t.name) ELSE NULL END',
        ];
    }

    /**
     * @param  Closure(): AggregateDefinition  $makeDefinition
     * @param  list<string>  $expected
     */
    #[DataProvider('watchColumnsCases')]
    public function test_watch_columns(Closure $makeDefinition, array $expected): void
    {
        $this->assertSame($expected, AggregateSqlEmitter::watchColumns($makeDefinition()));
    }

    /**
     * @return iterable<string, array{0: Closure(): AggregateDefinition, 1: list<string>}>
     */
    public static function watchColumnsCases(): iterable
    {
        yield 'scalar string_agg watches its source' => [
            static fn (): AggregateDefinition => Aggregate::stringAgg('name')->into('child_names'),
            ['name'],
        ];

        yield 'multi-column json_agg watches every source' => [
            static fn (): AggregateDefinition => Aggregate::jsonAgg(['id' => 'id', 'label' => 'name'])->into('descendant_summary'),
            ['id', 'name'],
        ];

        yield 'json_object_agg watches key and value' => [
            static fn (): AggregateDefinition => Aggregate::jsonObjectAgg(key: 'slug', value: 'name')->into('lookup'),
            ['slug', 'name'],
        ];
    }
}
