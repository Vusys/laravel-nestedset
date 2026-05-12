<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
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

    public function test_nested_set_macro_creates_four_columns(): void
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

    public function test_parent_id_is_nullable_and_bounds_are_not(): void
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

    public function test_drop_nested_set_macro_removes_four_columns(): void
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

    public function test_nested_set_macro_respects_custom_column_names(): void
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

    // ----------------------------------------------------------------
    // nestedSetAggregate() — Phase C
    // ----------------------------------------------------------------

    public function test_nested_set_aggregate_default_creates_non_null_default_zero_column(): void
    {
        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->nestedSetAggregate('tickets_total');
        });

        $column = $this->columnByName('tickets_total');

        $this->assertFalse($column['nullable'], 'sum/count column must be NOT NULL');
        $this->assertDefaultIsZero($column['default']);
    }

    public function test_nested_set_aggregate_sum_count_type_creates_non_null_default_zero_column(): void
    {
        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->nestedSetAggregate('tickets_count_all', type: 'sum_count');
        });

        $column = $this->columnByName('tickets_count_all');

        $this->assertFalse($column['nullable']);
        $this->assertDefaultIsZero($column['default']);
    }

    public function test_nested_set_aggregate_avg_type_creates_nullable_column_with_no_default(): void
    {
        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->nestedSetAggregate('tickets_avg', type: 'avg');
        });

        $column = $this->columnByName('tickets_avg');

        $this->assertTrue($column['nullable'], 'avg column must be nullable');
        $this->assertDefaultIsNull($column['default']);
    }

    public function test_nested_set_aggregate_min_max_type_creates_nullable_column_with_no_default(): void
    {
        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->nestedSetAggregate('tickets_max', type: 'min_max');
        });

        $column = $this->columnByName('tickets_max');

        $this->assertTrue($column['nullable'], 'min/max column must be nullable');
        $this->assertDefaultIsNull($column['default']);
    }

    public function test_nested_set_aggregate_rejects_unknown_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown type "bogus"');

        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->nestedSetAggregate('bad_col', type: 'bogus');
        });
    }

    public function test_drop_nested_set_aggregate_removes_the_column(): void
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

    public function test_nested_set_aggregate_supports_every_function_family_on_one_table(): void
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
}
