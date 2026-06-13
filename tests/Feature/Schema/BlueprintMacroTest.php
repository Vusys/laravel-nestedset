<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\NestedSetServiceProvider;
use Vusys\NestedSet\Tests\TestCase;

final class BlueprintMacroTest extends TestCase
{
    /** Schema tests mutate config; not relevant to tree integrity. */
    protected bool $allowBrokenTreeAtTearDown = true;

    private string $table = 'blueprint_macro_test';

    protected function tearDown(): void
    {
        Schema::dropIfExists($this->table);

        parent::tearDown();
    }

    #[Test]
    public function nested_set_macro_adds_lft_rgt_parent_id_and_depth_columns(): void
    {
        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->nestedSet();
        });

        $this->assertTrue(Schema::hasColumn($this->table, 'lft'));
        $this->assertTrue(Schema::hasColumn($this->table, 'rgt'));
        $this->assertTrue(Schema::hasColumn($this->table, 'parent_id'));
        $this->assertTrue(Schema::hasColumn($this->table, 'depth'));
    }

    #[Test]
    public function parent_id_is_nullable_and_bounds_are_not(): void
    {
        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->nestedSet();
        });

        $columns = Schema::getColumns($this->table);
        $byName = [];

        foreach ($columns as $column) {
            $byName[$column['name']] = $column;
        }

        $this->assertTrue($byName['parent_id']['nullable']);
        $this->assertFalse($byName['lft']['nullable']);
        $this->assertFalse($byName['rgt']['nullable']);
        $this->assertFalse($byName['depth']['nullable']);
    }

    #[Test]
    public function drop_nested_set_macro_removes_four_columns(): void
    {
        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->nestedSet();
        });

        Schema::table($this->table, function (Blueprint $table): void {
            $table->dropNestedSet();
        });

        $this->assertFalse(Schema::hasColumn($this->table, 'lft'));
        $this->assertFalse(Schema::hasColumn($this->table, 'rgt'));
        $this->assertFalse(Schema::hasColumn($this->table, 'parent_id'));
        $this->assertFalse(Schema::hasColumn($this->table, 'depth'));
    }

    #[Test]
    public function nested_set_index_columns_helper_default(): void
    {
        $cols = NestedSetServiceProvider::nestedSetIndexColumns(
            lft: 'lft',
            rgt: 'rgt',
            parentId: 'parent_id',
        );

        $this->assertSame(['lft', 'rgt', 'parent_id'], $cols);
    }

    #[Test]
    public function nested_set_index_columns_helper_with_scope_and_cover(): void
    {
        $cols = NestedSetServiceProvider::nestedSetIndexColumns(
            lft: 'lft',
            rgt: 'rgt',
            parentId: 'parent_id',
            scope: ['tenant_id', 'site_id'],
            cover: ['tickets'],
        );

        $this->assertSame(
            ['tenant_id', 'site_id', 'lft', 'rgt', 'parent_id', 'tickets'],
            $cols,
            'scope columns lead, cover columns trail',
        );
    }

    #[Test]
    public function nested_set_index_columns_helper_accepts_strings(): void
    {
        $cols = NestedSetServiceProvider::nestedSetIndexColumns(
            lft: 'lft',
            rgt: 'rgt',
            parentId: 'parent_id',
            scope: 'tenant_id',
            cover: 'tickets',
        );

        $this->assertSame(['tenant_id', 'lft', 'rgt', 'parent_id', 'tickets'], $cols);
    }

    #[Test]
    public function nested_set_macro_respects_custom_column_names(): void
    {
        config([
            'nestedset.columns.lft' => 'left_bound',
            'nestedset.columns.rgt' => 'right_bound',
            'nestedset.columns.parent_id' => 'node_parent',
            'nestedset.columns.depth' => 'node_depth',
        ]);

        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->nestedSet();
        });

        $this->assertTrue(Schema::hasColumn($this->table, 'left_bound'));
        $this->assertTrue(Schema::hasColumn($this->table, 'right_bound'));
        $this->assertTrue(Schema::hasColumn($this->table, 'node_parent'));
        $this->assertTrue(Schema::hasColumn($this->table, 'node_depth'));

        $this->assertFalse(Schema::hasColumn($this->table, 'lft'));
        $this->assertFalse(Schema::hasColumn($this->table, 'rgt'));
    }

    #[Test]
    public function bounded_index_name_returns_the_default_when_it_fits(): void
    {
        // Short composites keep Laravel's native auto-name verbatim, so
        // existing schemas' index names don't change.
        $this->assertSame(
            'categories_lft_rgt_parent_id_index',
            NestedSetServiceProvider::boundedIndexName('categories', ['lft', 'rgt', 'parent_id']),
        );
    }

    #[Test]
    public function bounded_index_name_shortens_an_over_long_default(): void
    {
        // A two-column scope on a 23-char table pushes the native name to
        // 65 chars — one over MySQL/MariaDB's cap. The bounded name fits,
        // is deterministic, and differs between the two nestedSet indexes.
        $primary = NestedSetServiceProvider::boundedIndexName(
            'multi_scoped_path_items',
            ['tenant_id', 'menu_id', 'lft', 'rgt', 'parent_id'],
        );
        $parent = NestedSetServiceProvider::boundedIndexName(
            'multi_scoped_path_items',
            ['tenant_id', 'menu_id', 'parent_id'],
        );

        $this->assertLessThanOrEqual(64, strlen($primary));
        $this->assertLessThanOrEqual(64, strlen($parent));
        $this->assertNotSame($primary, $parent, 'the two nestedSet indexes must not collide');
        // Deterministic: same inputs → same name.
        $this->assertSame(
            $primary,
            NestedSetServiceProvider::boundedIndexName(
                'multi_scoped_path_items',
                ['tenant_id', 'menu_id', 'lft', 'rgt', 'parent_id'],
            ),
        );
    }

    #[Test]
    public function nested_set_macro_builds_a_long_named_scoped_table_end_to_end(): void
    {
        // Regression: the auto-generated composite-index name for a
        // two-column scope on this table is 65 chars. On MySQL/MariaDB the
        // CREATE TABLE commits but the ALTER…ADD INDEX then fails (1059),
        // leaving a half-built table that breaks every later migration
        // ("table already exists"). The bounded name must let the whole
        // create — and the matching drop — round-trip on every backend.
        $longTable = 'multi_scoped_path_items_macro_probe';

        Schema::create($longTable, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('menu_id');
            $table->nestedSet(scope: ['tenant_id', 'menu_id']);
        });

        $this->assertTrue(Schema::hasColumn($longTable, 'lft'));
        $this->assertTrue(Schema::hasColumn($longTable, 'parent_id'));

        Schema::table($longTable, function (Blueprint $table): void {
            $table->dropNestedSet(scope: ['tenant_id', 'menu_id']);
        });

        $this->assertFalse(Schema::hasColumn($longTable, 'lft'));

        Schema::dropIfExists($longTable);
    }

    // ----------------------------------------------------------------
    // nestedSetAggregate() — Phase C
    // ----------------------------------------------------------------

    #[Test]
    public function nested_set_aggregate_default_creates_non_null_default_zero_column(): void
    {
        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->nestedSetAggregate('tickets_total');
        });

        $column = $this->columnByName('tickets_total');

        $this->assertFalse($column['nullable'], 'sum/count column must be NOT NULL');
        $this->assertDefaultIsZero($column['default']);
    }

    #[Test]
    public function nested_set_aggregate_sum_count_type_creates_non_null_default_zero_column(): void
    {
        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->nestedSetAggregate('tickets_count_all', type: 'sum_count');
        });

        $column = $this->columnByName('tickets_count_all');

        $this->assertFalse($column['nullable']);
        $this->assertDefaultIsZero($column['default']);
    }

    #[Test]
    public function nested_set_aggregate_avg_type_creates_nullable_column_with_no_default(): void
    {
        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->nestedSetAggregate('tickets_avg', type: 'avg');
        });

        $column = $this->columnByName('tickets_avg');

        $this->assertTrue($column['nullable'], 'avg column must be nullable');
        $this->assertDefaultIsNull($column['default']);
    }

    #[Test]
    public function nested_set_aggregate_min_max_type_creates_nullable_column_with_no_default(): void
    {
        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->nestedSetAggregate('tickets_max', type: 'min_max');
        });

        $column = $this->columnByName('tickets_max');

        $this->assertTrue($column['nullable'], 'min/max column must be nullable');
        $this->assertDefaultIsNull($column['default']);
    }

    #[Test]
    public function nested_set_aggregate_rejects_unknown_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown type "bogus"');

        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->nestedSetAggregate('bad_col', type: 'bogus');
        });
    }

    #[Test]
    public function drop_nested_set_aggregate_removes_the_column(): void
    {
        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->nestedSetAggregate('tickets_total');
        });

        $this->assertTrue(Schema::hasColumn($this->table, 'tickets_total'));

        Schema::table($this->table, function (Blueprint $table): void {
            $table->dropNestedSetAggregate('tickets_total');
        });

        $this->assertFalse(Schema::hasColumn($this->table, 'tickets_total'));
    }

    #[Test]
    public function nested_set_aggregate_avg_type_also_allocates_companion_columns(): void
    {
        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->nestedSetAggregate('tickets_avg', type: 'avg');
        });

        $byName = $this->columnsByName();

        $this->assertArrayHasKey('tickets_avg', $byName);
        $this->assertArrayHasKey('tickets_avg__sum', $byName);
        $this->assertArrayHasKey('tickets_avg__count', $byName);

        // Display column is nullable decimal.
        $this->assertTrue($byName['tickets_avg']['nullable']);

        // Companion columns are sum_count-shaped: non-null default 0.
        $this->assertFalse($byName['tickets_avg__sum']['nullable']);
        $this->assertFalse($byName['tickets_avg__count']['nullable']);
        $this->assertDefaultIsZero($byName['tickets_avg__sum']['default']);
        $this->assertDefaultIsZero($byName['tickets_avg__count']['default']);
    }

    #[Test]
    public function drop_nested_set_aggregate_avg_type_also_drops_companion_columns(): void
    {
        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->nestedSetAggregate('tickets_avg', type: 'avg');
        });

        $this->assertTrue(Schema::hasColumn($this->table, 'tickets_avg'));
        $this->assertTrue(Schema::hasColumn($this->table, 'tickets_avg__sum'));
        $this->assertTrue(Schema::hasColumn($this->table, 'tickets_avg__count'));

        Schema::table($this->table, function (Blueprint $table): void {
            $table->dropNestedSetAggregate('tickets_avg', type: 'avg');
        });

        $this->assertFalse(Schema::hasColumn($this->table, 'tickets_avg'));
        $this->assertFalse(Schema::hasColumn($this->table, 'tickets_avg__sum'));
        $this->assertFalse(Schema::hasColumn($this->table, 'tickets_avg__count'));
    }

    #[Test]
    public function companion_columns_for_returns_avg_companion_names(): void
    {
        $companions = NestedSetServiceProvider::companionColumnsFor('tickets_avg', 'avg');

        $this->assertSame(['tickets_avg__sum', 'tickets_avg__count'], $companions);
    }

    #[Test]
    public function companion_columns_for_returns_empty_list_for_non_avg_types(): void
    {
        $this->assertSame([], NestedSetServiceProvider::companionColumnsFor('tickets_total', 'sum_count'));
        $this->assertSame([], NestedSetServiceProvider::companionColumnsFor('tickets_max', 'min_max'));
    }

    #[Test]
    public function nested_set_aggregate_variance_and_stddev_types_allocate_three_companions_each(): void
    {
        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->nestedSetAggregate('tickets_variance', type: 'variance');
            $table->nestedSetAggregate('tickets_stddev', type: 'stddev');
        });

        $byName = $this->columnsByName();

        // Display columns: nullable.
        $this->assertTrue($byName['tickets_variance']['nullable']);
        $this->assertTrue($byName['tickets_stddev']['nullable']);

        // Three companions each — Sum, SumSq, Count — non-null with default 0.
        foreach (['tickets_variance', 'tickets_stddev'] as $display) {
            foreach (['__sum', '__sum_sq', '__count'] as $suffix) {
                $col = $display.$suffix;
                $this->assertArrayHasKey($col, $byName, "missing companion {$col}");
                $this->assertFalse($byName[$col]['nullable'], "{$col} should be non-null");
                $this->assertDefaultIsZero($byName[$col]['default']);
            }
        }
    }

    #[Test]
    public function drop_nested_set_aggregate_variance_type_drops_all_three_companions(): void
    {
        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->nestedSetAggregate('tickets_variance', type: 'variance');
        });

        foreach (['tickets_variance', 'tickets_variance__sum', 'tickets_variance__sum_sq', 'tickets_variance__count'] as $col) {
            $this->assertTrue(Schema::hasColumn($this->table, $col), "expected {$col} created");
        }

        Schema::table($this->table, function (Blueprint $table): void {
            $table->dropNestedSetAggregate('tickets_variance', type: 'variance');
        });

        foreach (['tickets_variance', 'tickets_variance__sum', 'tickets_variance__sum_sq', 'tickets_variance__count'] as $col) {
            $this->assertFalse(Schema::hasColumn($this->table, $col), "expected {$col} dropped");
        }
    }

    #[Test]
    public function companion_columns_for_returns_variance_companions(): void
    {
        $this->assertSame(
            ['tickets_variance__sum', 'tickets_variance__sum_sq', 'tickets_variance__count'],
            NestedSetServiceProvider::companionColumnsFor('tickets_variance', 'variance'),
        );
        $this->assertSame(
            ['tickets_stddev__sum', 'tickets_stddev__sum_sq', 'tickets_stddev__count'],
            NestedSetServiceProvider::companionColumnsFor('tickets_stddev', 'stddev'),
        );
    }

    #[Test]
    public function companion_allocations_assign_sum_sq_to_a_wider_decimal_shape(): void
    {
        $this->assertSame(
            [
                ['column' => 'tickets_variance__sum', 'type' => 'sum_count'],
                ['column' => 'tickets_variance__sum_sq', 'type' => 'sum_sq'],
                ['column' => 'tickets_variance__count', 'type' => 'sum_count'],
            ],
            NestedSetServiceProvider::companionAllocationsFor('tickets_variance', 'variance'),
        );
    }

    #[Test]
    public function sum_sq_companion_is_emitted_as_a_high_precision_decimal(): void
    {
        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->nestedSetAggregate('tickets_variance', type: 'variance');
        });

        $byName = $this->columnsByName();

        // sum_sq companion → decimal/numeric, wide enough to absorb
        // squared-source contributions without overflowing the bigInt
        // ceiling that the plain Sum companion uses. (Backends report
        // bigInteger differently — 'bigint' on MySQL/MariaDB/PG,
        // 'integer' on SQLite — so we don't pin the Sum side here; the
        // sum_sq side is what matters for this regression.)
        $sumSqType = strtolower($this->columnTypeName($byName['tickets_variance__sum_sq']));
        $this->assertTrue(
            str_starts_with($sumSqType, 'decimal') || str_starts_with($sumSqType, 'numeric'),
            "expected decimal/numeric type for __sum_sq companion, got {$sumSqType}",
        );
    }

    /**
     * Returns the backend-specific type name string from a Laravel
     * Schema::getColumns() row. SQLite reports it under `type`
     * (`integer`, `numeric`); MySQL / MariaDB / PG return things
     * like `bigint`, `decimal(38,0)`, `numeric(38,0)` — the prefix
     * is what matters for the test.
     *
     * @param  array<string, mixed>  $column
     */
    private function columnTypeName(array $column): string
    {
        $type = $column['type_name'] ?? $column['type'] ?? '';

        return is_string($type) ? $type : '';
    }

    #[Test]
    public function companion_columns_for_rejects_unknown_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown type "bogus"');

        NestedSetServiceProvider::companionColumnsFor('tickets', 'bogus');
    }

    #[Test]
    public function nested_set_aggregate_supports_every_function_family_on_one_table(): void
    {
        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->nestedSetAggregate('tickets_total');
            $table->nestedSetAggregate('tickets_count', type: 'sum_count');
            $table->nestedSetAggregate('tickets_avg', type: 'avg');
            $table->nestedSetAggregate('tickets_min', type: 'min_max');
            $table->nestedSetAggregate('tickets_max', type: 'min_max');
        });

        $byName = $this->columnsByName();

        $this->assertFalse($byName['tickets_total']['nullable']);
        $this->assertFalse($byName['tickets_count']['nullable']);
        $this->assertTrue($byName['tickets_avg']['nullable']);
        $this->assertTrue($byName['tickets_min']['nullable']);
        $this->assertTrue($byName['tickets_max']['nullable']);
    }

    #[Test]
    public function nested_set_aggregate_geometric_mean_type_allocates_high_precision_sum_and_integer_count(): void
    {
        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->nestedSetAggregate('value_gmean', type: 'geometric_mean');
        });

        $byName = $this->columnsByName();

        $this->assertTrue($byName['value_gmean']['nullable'], 'display column should be nullable');
        $this->assertFalse($byName['value_gmean__sum_log']['nullable'], '__sum_log must be non-null');
        $this->assertDefaultIsZero($byName['value_gmean__sum_log']['default']);
        $this->assertFalse($byName['value_gmean__count']['nullable'], '__count must be non-null');
        $this->assertDefaultIsZero($byName['value_gmean__count']['default']);
    }

    #[Test]
    public function nested_set_aggregate_harmonic_mean_type_allocates_high_precision_sum_and_integer_count(): void
    {
        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->nestedSetAggregate('value_hmean', type: 'harmonic_mean');
        });

        $byName = $this->columnsByName();

        $this->assertTrue($byName['value_hmean']['nullable'], 'display column should be nullable');
        $this->assertFalse($byName['value_hmean__sum_recip']['nullable'], '__sum_recip must be non-null');
        $this->assertDefaultIsZero($byName['value_hmean__sum_recip']['default']);
        $this->assertFalse($byName['value_hmean__count']['nullable'], '__count must be non-null');
        $this->assertDefaultIsZero($byName['value_hmean__count']['default']);
    }

    #[Test]
    public function drop_nested_set_aggregate_geometric_mean_type_drops_all_companions(): void
    {
        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->nestedSetAggregate('value_gmean', type: 'geometric_mean');
        });

        Schema::table($this->table, function (Blueprint $table): void {
            $table->dropNestedSetAggregate('value_gmean', type: 'geometric_mean');
        });

        $byName = $this->columnsByName();

        $this->assertArrayNotHasKey('value_gmean', $byName);
        $this->assertArrayNotHasKey('value_gmean__sum_log', $byName);
        $this->assertArrayNotHasKey('value_gmean__count', $byName);
    }

    #[Test]
    public function drop_nested_set_aggregate_harmonic_mean_type_drops_all_companions(): void
    {
        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->nestedSetAggregate('value_hmean', type: 'harmonic_mean');
        });

        Schema::table($this->table, function (Blueprint $table): void {
            $table->dropNestedSetAggregate('value_hmean', type: 'harmonic_mean');
        });

        $byName = $this->columnsByName();

        $this->assertArrayNotHasKey('value_hmean', $byName);
        $this->assertArrayNotHasKey('value_hmean__sum_recip', $byName);
        $this->assertArrayNotHasKey('value_hmean__count', $byName);
    }

    #[Test]
    public function companion_allocations_for_returns_per_companion_storage_types(): void
    {
        $gmean = NestedSetServiceProvider::companionAllocationsFor('v_gmean', 'geometric_mean');
        $hmean = NestedSetServiceProvider::companionAllocationsFor('v_hmean', 'harmonic_mean');
        $avg = NestedSetServiceProvider::companionAllocationsFor('v_avg', 'avg');

        $this->assertSame([
            ['column' => 'v_gmean__sum_log', 'type' => 'high_precision_sum'],
            ['column' => 'v_gmean__count', 'type' => 'sum_count'],
        ], $gmean);

        $this->assertSame([
            ['column' => 'v_hmean__sum_recip', 'type' => 'high_precision_sum'],
            ['column' => 'v_hmean__count', 'type' => 'sum_count'],
        ], $hmean);

        $this->assertSame([
            ['column' => 'v_avg__sum', 'type' => 'sum_count'],
            ['column' => 'v_avg__count', 'type' => 'sum_count'],
        ], $avg);
    }

    /**
     * Backends format integer defaults differently — SQLite as the
     * literal `'0'` string (quotes preserved from the CREATE TABLE SQL),
     * MySQL/MariaDB/PostgreSQL as bare `0`. Trim quotes and compare
     * numerically so the assertion works everywhere.
     */
    private function assertDefaultIsZero(mixed $default): void
    {
        if ($default === null) {
            $this->fail('Expected a default of 0, got null.');
        }
        if (! is_string($default) && ! is_numeric($default)) {
            $this->fail('Expected scalar default, got '.get_debug_type($default));
        }

        $this->assertSame(0, (int) trim((string) $default, "'\""));
    }

    /**
     * MariaDB's information_schema reports columns with no default as
     * the literal string `NULL`; SQLite and PostgreSQL return PHP null.
     * Treat either as "no default" so the same test passes everywhere.
     */
    private function assertDefaultIsNull(mixed $default): void
    {
        if ($default === null) {
            return;
        }

        $this->assertSame('NULL', $default, 'expected null default (or MariaDB literal NULL)');
    }

    /**
     * @return array<string, mixed>
     */
    private function columnByName(string $name): array
    {
        $byName = $this->columnsByName();

        if (! isset($byName[$name])) {
            $this->fail("Column {$name} not found on table {$this->table}.");
        }

        return $byName[$name];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function columnsByName(): array
    {
        $byName = [];

        foreach (Schema::getColumns($this->table) as $column) {
            $byName[$column['name']] = $column;
        }

        return $byName;
    }

    #[Test]
    public function nested_set_macro_adds_a_parent_id_index(): void
    {
        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->nestedSet();
        });

        $hasParentIndex = false;
        foreach (Schema::getIndexes($this->table) as $index) {
            if ($index['columns'] === ['parent_id']) {
                $hasParentIndex = true;
                break;
            }
        }

        $this->assertTrue($hasParentIndex, 'nestedSet() must add an index keyed on [parent_id]');
    }

    #[Test]
    public function scoped_nested_set_macro_adds_a_scope_parent_id_index(): void
    {
        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('menu_id');
            $table->nestedSet(scope: 'menu_id');
        });

        $hasScopedParentIndex = false;
        foreach (Schema::getIndexes($this->table) as $index) {
            if ($index['columns'] === ['menu_id', 'parent_id']) {
                $hasScopedParentIndex = true;
                break;
            }
        }

        $this->assertTrue($hasScopedParentIndex, 'scoped nestedSet() must add an index keyed on [scope…, parent_id]');
    }
}
