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
}
