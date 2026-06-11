<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Import\Json;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\Menu;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\Fixtures\Models\ScopedArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * fromJsonTree() seeding ROOTS on a scoped model. The scope comes from
 * the JSON root rows (validated present and uniform); no anchor is
 * required. Previously this was a dead end — the importer validated the
 * scope columns then bulkInsertTree(null) unconditionally threw.
 */
final class ScopedJsonImportTest extends TestCase
{
    use InteractsWithTrees;

    #[Test]
    public function imports_roots_into_the_scope_carried_by_the_json(): void
    {
        $menu = Menu::create(['name' => 'M']);

        $payload = [
            ['name' => 'Root', 'menu_id' => $menu->id, 'children' => [
                ['name' => 'A', 'menu_id' => $menu->id, 'children' => []],
                ['name' => 'B', 'menu_id' => $menu->id, 'children' => []],
            ]],
        ];

        $inserted = MenuItem::fromJsonTree($payload);
        $this->assertCount(3, $inserted);

        $root = MenuItem::query()->where('menu_id', $menu->id)->where('name', 'Root')->firstOrFail();
        $this->assertTrue($root->isRoot());
        $this->assertSame(
            ['A', 'B'],
            MenuItem::query()->where('parent_id', $root->id)->orderBy('lft')->pluck('name')->all(),
        );
        $this->assertFalse(MenuItem::isBroken($root));
    }

    #[Test]
    public function imported_scope_is_isolated_from_an_existing_scope(): void
    {
        $menu1 = Menu::create(['name' => 'One']);
        $menu2 = Menu::create(['name' => 'Two']);

        // Seed menu 1 first, then import into menu 2 — menu 2's lft must
        // restart at 1, independent of menu 1's bounds.
        MenuItem::fromJsonTree([['name' => 'R1', 'menu_id' => $menu1->id, 'children' => []]]);
        MenuItem::fromJsonTree([['name' => 'R2', 'menu_id' => $menu2->id, 'children' => [
            ['name' => 'R2a', 'menu_id' => $menu2->id, 'children' => []],
        ]]]);

        $r2 = MenuItem::query()->where('menu_id', $menu2->id)->where('name', 'R2')->firstOrFail();
        $this->assertSame(1, $r2->lft, 'menu 2 restarts its own lft sequence');
        $this->assertFalse(MenuItem::isBroken(MenuItem::query()->where('menu_id', $menu1->id)->first()));
        $this->assertFalse(MenuItem::isBroken($r2));
    }

    #[Test]
    public function scoped_aggregate_model_roots_get_correct_aggregates(): void
    {
        $payload = [
            ['name' => 'Root', 'tenant_id' => 7, 'amount' => 1, 'children' => [
                ['name' => 'A', 'tenant_id' => 7, 'amount' => 10, 'children' => []],
                ['name' => 'B', 'tenant_id' => 7, 'amount' => 100, 'children' => []],
            ]],
        ];

        ScopedArea::fromJsonTree($payload);

        $root = ScopedArea::query()->where('tenant_id', 7)->where('name', 'Root')->firstOrFail();
        $this->assertSame(111, (int) $root->amount_total);
        $this->assertFalse(ScopedArea::aggregatesAreBroken($root));
        $this->assertFalse(ScopedArea::isBroken($root));
    }

    #[Test]
    public function rows_missing_the_scope_column_are_rejected(): void
    {
        $this->expectException(ScopeViolationException::class);
        $this->expectExceptionMessage('missing scope column');

        MenuItem::fromJsonTree([['name' => 'Root', 'children' => []]]);
    }

    #[Test]
    public function rows_spanning_multiple_scopes_are_rejected(): void
    {
        $a = Menu::create(['name' => 'A']);
        $b = Menu::create(['name' => 'B']);

        $this->expectException(ScopeViolationException::class);
        $this->expectExceptionMessage('span multiple scopes');

        MenuItem::fromJsonTree([
            ['name' => 'Ra', 'menu_id' => $a->id, 'children' => []],
            ['name' => 'Rb', 'menu_id' => $b->id, 'children' => []],
        ]);
    }
}
