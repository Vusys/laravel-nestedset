<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Fixtures\Models\Menu;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Seeded random fuzzing for `bulkInsertTree`.
 *
 * `BulkInsertTest` covers the contract one scenario at a time. This
 * file generates *random* nested specs (depth 1-4, fanout 1-4) and
 * asserts the saved tree matches the spec — shape, parent ids, depth,
 * ordering, scope columns, aggregate convergence, and bounds
 * contiguity at the anchor.
 *
 * Strategy: build a spec → call bulkInsertTree → walk the resulting
 * models in DFS pre-order and verify each row against the spec node it
 * was generated from. The mapping is deterministic because
 * bulkInsertTree's plan walks in DFS pre-order and returns models in
 * that order.
 *
 * Anchor variants covered:
 *   - No anchor (new roots, both into empty tables and into a table
 *     with an existing forest — the new roots must start past
 *     MAX(rgt))
 *   - Anchor is a fresh root with no children
 *   - Anchor is an interior node (the new subtree must fit inside the
 *     gap without breaking neighbouring rows)
 *   - Anchor is a leaf
 *   - Scoped model (MenuItem) anchored to a menu
 */
final class BulkInsertFuzzerTest extends TestCase
{
    /**
     * @return iterable<string, array{seed: int, runs: int}>
     */
    public static function seedProvider(): iterable
    {
        yield 'seed 1' => ['seed' => 1, 'runs' => 8];

        yield 'seed 42' => ['seed' => 42, 'runs' => 8];

        yield 'seed 1337' => ['seed' => 1337, 'runs' => 8];

        yield 'seed 9999' => ['seed' => 9999, 'runs' => 12];

        yield 'seed 314159' => ['seed' => 314159, 'runs' => 12];
    }

    // ================================================================
    // Unscoped + aggregated model (Area)
    // ================================================================

    #[DataProvider('seedProvider')]
    public function test_random_specs_round_trip_for_area(int $seed, int $runs): void
    {
        mt_srand($seed);

        for ($run = 1; $run <= $runs; $run++) {
            // Each iteration uses a fresh table — truncate any prior
            // run so MAX(rgt) doesn't accumulate across iterations.
            DB::table('areas')->delete();

            $tag = "[seed={$seed} run={$run}]";
            $anchorMode = $this->pickAnchorMode(mt_rand(0, 99));

            $anchor = $this->plantAnchor($anchorMode);
            $existingMaxRgt = $this->currentMaxRgt('areas');

            $spec = $this->makeAreaSpec(depth: 0, maxDepth: mt_rand(1, 4));
            // Force the top level to be a list of 1-3 sibling subtrees
            // so we exercise multi-root insertion paths.
            $specs = [$spec];
            $extraSiblings = mt_rand(0, 2);
            for ($i = 0; $i < $extraSiblings; $i++) {
                $specs[] = $this->makeAreaSpec(depth: 0, maxDepth: mt_rand(1, 3));
            }

            $inserted = Area::bulkInsertTree($specs, appendTo: $anchor);

            $expectedCount = $this->countSpecNodes($specs);
            $this->assertCount(
                $expectedCount,
                $inserted,
                "{$tag} expected {$expectedCount} models, got ".count($inserted),
            );

            // Verify DFS pre-order shape against spec.
            $expectedParentId = null;
            if ($anchor !== null) {
                $key = $anchor->getKey();
                if (! is_int($key)) {
                    $this->fail("{$tag} anchor key isn't an int");
                }
                $expectedParentId = $key;
            }

            $idx = 0;
            foreach ($specs as $rootSpec) {
                $idx = $this->assertSpecMatches(
                    spec: $rootSpec,
                    models: $inserted,
                    cursor: $idx,
                    expectedParentId: $expectedParentId,
                    expectedDepth: $anchor !== null ? ((int) $anchor->depth) + 1 : 0,
                    tag: $tag,
                );
            }

            // Bounds contiguity: the new subtree must fill (anchor.rgt, anchor.rgt + 2N) tightly.
            $newLfts = array_map(fn (Area $a): int => $a->lft, $inserted);
            $newRgts = array_map(fn (Area $a): int => $a->rgt, $inserted);
            if ($newLfts === [] || $newRgts === []) {
                $this->fail("{$tag} bulkInsertTree returned no rows");
            }
            $minNewLft = min($newLfts);
            $maxNewRgt = max($newRgts);

            if ($anchor !== null) {
                $anchor->refresh();
                $this->assertLessThan($anchor->rgt, $maxNewRgt, "{$tag} new subtree must sit inside anchor's range");
                $this->assertGreaterThan($anchor->lft, $minNewLft, "{$tag} new subtree must sit inside anchor's range");
            } else {
                // New roots — must start past the previous max(rgt).
                $this->assertSame(
                    $existingMaxRgt + 1,
                    $minNewLft,
                    "{$tag} new roots must start at existingMaxRgt+1",
                );
            }

            // No bound duplicates anywhere in the table.
            $this->assertBoundsAreSequential('areas', $tag);

            // Built-in integrity sanity check.
            $this->assertFalse(Area::isBroken(), "{$tag} tree is broken");

            // Aggregate correctness — bulkInsertTree fixes aggregates on
            // exit. Snapshot expected values from a re-read and compare
            // against stored.
            $this->assertAreaAggregatesMatchFresh($tag);
        }
    }

    // ================================================================
    // Scoped model (MenuItem) — verify scope is copied onto every row
    // ================================================================

    #[DataProvider('seedProvider')]
    public function test_random_specs_scope_correctly_for_menu_item(int $seed, int $runs): void
    {
        mt_srand($seed);

        for ($run = 1; $run <= $runs; $run++) {
            DB::table('menu_items')->delete();
            DB::table('menus')->delete();

            $tag = "[seed={$seed} run={$run}]";

            // Two menus — one targeted, one bystander. The bystander
            // gets a small tree planted up-front so we can verify it's
            // untouched after the bulk insert.
            $target = Menu::create(['name' => 'target']);
            $bystander = Menu::create(['name' => 'bystander']);

            $bystanderRoot = new MenuItem(['name' => 'b_root', 'menu_id' => $bystander->id]);
            $bystanderRoot->saveAsRoot();
            $bystanderRoot->refresh();

            // Plant a few rows in bystander to make the snapshot
            // diff meaningful.
            for ($i = 0; $i < mt_rand(2, 5); $i++) {
                $node = new MenuItem(['name' => "b_n{$i}", 'menu_id' => $bystander->id]);
                $node->appendToNode($bystanderRoot->refresh())->save();
            }

            $bystanderSnap = $this->snapshotMenuItems((int) $bystander->id);

            // Plant the target root.
            $targetRoot = new MenuItem(['name' => 't_root', 'menu_id' => $target->id]);
            $targetRoot->saveAsRoot();
            $targetRoot->refresh();

            $spec = $this->makeMenuItemSpec(depth: 0, maxDepth: mt_rand(1, 3));
            $specs = [$spec];

            $inserted = MenuItem::bulkInsertTree($specs, appendTo: $targetRoot);

            $targetRootKey = $targetRoot->getKey();
            if (! is_int($targetRootKey)) {
                $this->fail("{$tag} targetRoot key isn't an int");
            }

            // Every inserted row carries the target menu_id.
            foreach ($inserted as $row) {
                $this->assertSame(
                    (int) $target->id,
                    (int) $row->menu_id,
                    "{$tag} inserted row {$row->name} got the wrong menu_id",
                );
            }

            // The bystander menu's rows are byte-identical to before.
            $bystanderNow = $this->snapshotMenuItems((int) $bystander->id);
            $this->assertSame(
                $bystanderSnap,
                $bystanderNow,
                "{$tag} bystander menu was disturbed by a bulk insert into target",
            );

            // Walk the target subtree and verify the shape matches.
            $targetRoot->refresh();
            $this->assertSpecMatches(
                spec: $spec,
                models: $inserted,
                cursor: 0,
                expectedParentId: $targetRootKey,
                expectedDepth: ((int) $targetRoot->depth) + 1,
                tag: $tag,
            );

            $this->assertFalse(
                MenuItem::isBroken(new MenuItem(['menu_id' => $target->id])),
                "{$tag} target tree broken",
            );
            $this->assertFalse(
                MenuItem::isBroken(new MenuItem(['menu_id' => $bystander->id])),
                "{$tag} bystander tree broken",
            );
        }
    }

    // ================================================================
    // Spec generation
    // ================================================================

    /**
     * @return array{name: string, tickets: int, children?: list<array<string, mixed>>}
     */
    private function makeAreaSpec(int $depth, int $maxDepth): array
    {
        static $counter = 0;

        $node = [
            'name' => 'n'.(++$counter),
            'tickets' => mt_rand(0, 100),
        ];

        if ($depth < $maxDepth) {
            $childCount = mt_rand(0, 4);
            if ($childCount > 0) {
                $children = [];
                for ($i = 0; $i < $childCount; $i++) {
                    $children[] = $this->makeAreaSpec($depth + 1, $maxDepth);
                }
                $node['children'] = $children;
            }
        }

        return $node;
    }

    /**
     * @return array{name: string, children?: list<array<string, mixed>>}
     */
    private function makeMenuItemSpec(int $depth, int $maxDepth): array
    {
        static $counter = 0;

        $node = ['name' => 'mi'.(++$counter)];

        if ($depth < $maxDepth) {
            $childCount = mt_rand(0, 3);
            if ($childCount > 0) {
                $children = [];
                for ($i = 0; $i < $childCount; $i++) {
                    $children[] = $this->makeMenuItemSpec($depth + 1, $maxDepth);
                }
                $node['children'] = $children;
            }
        }

        return $node;
    }

    /**
     * @param  list<array<string, mixed>>  $specs
     */
    private function countSpecNodes(array $specs): int
    {
        $total = 0;
        foreach ($specs as $spec) {
            $total++;
            $rawChildren = $spec['children'] ?? null;
            if (is_array($rawChildren)) {
                /** @var list<array<string, mixed>> $children */
                $children = array_values($rawChildren);
                $total += $this->countSpecNodes($children);
            }
        }

        return $total;
    }

    // ================================================================
    // Spec ↔ model walker
    // ================================================================

    /**
     * Walks the spec in DFS pre-order in lockstep with the models list.
     * For each spec node, asserts the model at `cursor` matches:
     *   - same `name`
     *   - parent_id equals the immediately enclosing spec node's saved id
     *     (or `$expectedParentId` for top-level spec entries)
     *   - depth equals the enclosing depth
     *
     * @param  array<string, mixed>  $spec
     * @param  list<Model>  $models
     */
    private function assertSpecMatches(
        array $spec,
        array $models,
        int $cursor,
        ?int $expectedParentId,
        int $expectedDepth,
        string $tag,
    ): int {
        $specName = $this->specString($spec, 'name', $tag);

        $this->assertArrayHasKey(
            $cursor,
            $models,
            "{$tag} ran out of models before consuming the spec — expected node '{$specName}'",
        );
        $model = $models[$cursor];

        $modelName = $model->getAttribute('name');
        if (! is_string($modelName)) {
            $this->fail("{$tag} cursor {$cursor}: model name attribute isn't a string");
        }

        $this->assertSame(
            $specName,
            $modelName,
            "{$tag} cursor {$cursor}: name mismatch (spec='{$specName}', model='{$modelName}')",
        );

        $modelParentId = $model->getAttribute('parent_id');
        $this->assertSame(
            $expectedParentId,
            $modelParentId,
            "{$tag} cursor {$cursor} ({$specName}): parent_id mismatch",
        );

        $modelDepth = $model->getAttribute('depth');
        $this->assertSame(
            $expectedDepth,
            $modelDepth,
            "{$tag} cursor {$cursor} ({$specName}): depth mismatch",
        );

        $thisKey = $model->getKey();
        $thisId = is_int($thisKey) ? $thisKey : null;
        $cursor++;

        $rawChildren = $spec['children'] ?? null;
        if (is_array($rawChildren)) {
            /** @var list<array<string, mixed>> $children */
            $children = array_values($rawChildren);
            foreach ($children as $childSpec) {
                $cursor = $this->assertSpecMatches(
                    spec: $childSpec,
                    models: $models,
                    cursor: $cursor,
                    expectedParentId: $thisId,
                    expectedDepth: $expectedDepth + 1,
                    tag: $tag,
                );
            }
        }

        return $cursor;
    }

    /**
     * Reads a string key from a spec array, failing the test with
     * context if it's missing or not a string. Acts as a type-narrowing
     * guard so PHPStan accepts the value in string contexts downstream.
     *
     * @param  array<string, mixed>  $spec
     */
    private function specString(array $spec, string $key, string $tag): string
    {
        $value = $spec[$key] ?? null;
        if (! is_string($value)) {
            $this->fail("{$tag} spec key '{$key}' isn't a string");
        }

        return $value;
    }

    // ================================================================
    // Anchor planting
    // ================================================================

    private function pickAnchorMode(int $roll): string
    {
        if ($roll < 20) {
            return 'no_anchor_empty_table';
        }
        if ($roll < 35) {
            return 'no_anchor_with_existing_forest';
        }
        if ($roll < 55) {
            return 'anchor_root';
        }
        if ($roll < 80) {
            return 'anchor_interior';
        }

        return 'anchor_leaf';
    }

    private function plantAnchor(string $mode): ?Area
    {
        switch ($mode) {
            case 'no_anchor_empty_table':
                return null;

            case 'no_anchor_with_existing_forest':
                // Pre-existing forest the new roots must sit past.
                for ($i = 0; $i < mt_rand(1, 3); $i++) {
                    $root = new Area(['name' => "pre_root_{$i}", 'tickets' => mt_rand(0, 10)]);
                    $root->saveAsRoot();
                    // Maybe a child or two.
                    if (mt_rand(0, 1) === 1) {
                        $child = new Area(['name' => "pre_root_{$i}_c", 'tickets' => mt_rand(0, 10)]);
                        $child->appendToNode($root->refresh())->save();
                    }
                }

                return null;

            case 'anchor_root':
                $root = new Area(['name' => 'anchor_root', 'tickets' => mt_rand(0, 10)]);
                $root->saveAsRoot();

                return $root->refresh();

            case 'anchor_interior':
                $root = new Area(['name' => 'anchor_root_for_interior', 'tickets' => mt_rand(0, 10)]);
                $root->saveAsRoot();
                $root->refresh();
                $mid = new Area(['name' => 'anchor_mid', 'tickets' => mt_rand(0, 10)]);
                $mid->appendToNode($root)->save();
                $root->refresh();
                $mid->refresh();
                // Add a couple of siblings to make 'interior' meaningful.
                $sib1 = new Area(['name' => 'anchor_sib1', 'tickets' => mt_rand(0, 10)]);
                $sib1->appendToNode($root)->save();
                $sib2 = new Area(['name' => 'anchor_sib2', 'tickets' => mt_rand(0, 10)]);
                $sib2->appendToNode($root)->save();

                return $mid->refresh();

            case 'anchor_leaf':
                $root = new Area(['name' => 'anchor_root_for_leaf', 'tickets' => mt_rand(0, 10)]);
                $root->saveAsRoot();
                $root->refresh();
                $leaf = new Area(['name' => 'anchor_leaf', 'tickets' => mt_rand(0, 10)]);
                $leaf->appendToNode($root)->save();

                return $leaf->refresh();
        }

        return null;
    }

    // ================================================================
    // Helpers
    // ================================================================

    private function currentMaxRgt(string $table): int
    {
        $raw = DB::table($table)->max('rgt');

        return is_numeric($raw) ? (int) $raw : 0;
    }

    private function assertBoundsAreSequential(string $table, string $tag): void
    {
        $bounds = [];
        foreach (DB::table($table)->get(['lft', 'rgt']) as $row) {
            $bounds[] = (int) $row->lft;
            $bounds[] = (int) $row->rgt;
        }
        sort($bounds);
        $expected = range(1, count($bounds));
        $this->assertSame(
            $expected,
            $bounds,
            "{$tag} {$table} bounds aren't a permutation of 1..".count($bounds),
        );
    }

    private function assertAreaAggregatesMatchFresh(string $tag): void
    {
        /** @var list<Area> $areas */
        $areas = Area::query()->orderBy('lft')->get()->all();
        foreach ($areas as $area) {
            $id = $area->id;
            $name = $area->name;
            foreach (['tickets_total', 'tickets_count_all', 'tickets_min', 'tickets_max'] as $col) {
                $stored = $area->getAttribute($col);
                $fresh = $area->freshAggregate($col);
                $this->assertSame(
                    $fresh,
                    $stored,
                    "{$tag} #{$id} ({$name}): {$col} mismatch — stored=".json_encode($stored).' fresh='.json_encode($fresh),
                );
            }
        }
    }

    /**
     * @return array<int, array{lft: int, rgt: int, depth: int, parent_id: int|null}>
     */
    private function snapshotMenuItems(int $menuId): array
    {
        $rows = DB::table('menu_items')
            ->where('menu_id', $menuId)
            ->orderBy('id')
            ->get(['id', 'lft', 'rgt', 'depth', 'parent_id'])
            ->all();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->id] = [
                'lft' => (int) $row->lft,
                'rgt' => (int) $row->rgt,
                'depth' => (int) $row->depth,
                'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
            ];
        }

        return $out;
    }
}
