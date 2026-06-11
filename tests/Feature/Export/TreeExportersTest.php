<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Export;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\CorruptTreeException;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\Export\AsciiOptions;
use Vusys\NestedSet\Export\DotOptions;
use Vusys\NestedSet\Export\JsonOptions;
use Vusys\NestedSet\Export\MermaidOptions;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\Fixtures\Models\Menu;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\Fixtures\Models\UuidTag;
use Vusys\NestedSet\Tests\TestCase;
use Vusys\NestedSet\Walker\WalkFilter;

/**
 * Snapshot-style tests for the tree exporters. All fixtures build the
 * same five-node shape:
 *
 *   Electronics
 *   ├── Laptops
 *   └── Phones
 *       ├── iPhone
 *       └── Android
 */
final class TreeExportersTest extends TestCase
{
    /** @return array{Category, Category, Category, Category, Category} */
    private function buildElectronicsTree(): array
    {
        $electronics = new Category(['name' => 'Electronics']);
        $electronics->saveAsRoot();
        $electronics = $electronics->refresh();

        $laptops = new Category(['name' => 'Laptops']);
        $laptops->appendToNode($electronics)->save();

        $phones = new Category(['name' => 'Phones']);
        $phones->appendToNode($electronics->refresh())->save();

        $iphone = new Category(['name' => 'iPhone']);
        $iphone->appendToNode($phones->refresh())->save();

        $android = new Category(['name' => 'Android']);
        $android->appendToNode($phones->refresh())->save();

        return [$electronics->refresh(), $laptops->refresh(), $phones->refresh(), $iphone->refresh(), $android->refresh()];
    }

    #[Test]
    public function ascii_tree_unicode_default(): void
    {
        [$electronics] = $this->buildElectronicsTree();

        $expected = "Electronics\n"
            ."├── Laptops\n"
            ."└── Phones\n"
            ."    ├── iPhone\n"
            .'    └── Android';

        $this->assertSame($expected, $electronics->toAsciiTree());
    }

    #[Test]
    public function ascii_tree_plain_ascii_fallback(): void
    {
        [$electronics] = $this->buildElectronicsTree();

        $expected = "Electronics\n"
            ."|-- Laptops\n"
            ."`-- Phones\n"
            ."    |-- iPhone\n"
            .'    `-- Android';

        $this->assertSame($expected, $electronics->toAsciiTree(new AsciiOptions(unicode: false)));
    }

    #[Test]
    public function ascii_tree_renders_vertical_continuation_for_deep_nodes(): void
    {
        $root = new Category(['name' => 'A']);
        $root->saveAsRoot();
        $root = $root->refresh();

        $b = new Category(['name' => 'B']);
        $b->appendToNode($root)->save();

        $c = new Category(['name' => 'C']);
        $c->appendToNode($b->refresh())->save();

        $d = new Category(['name' => 'D']);
        $d->appendToNode($root->refresh())->save();

        // B is NOT last, so C's prefix must include the vertical bar.
        $tree = $root->refresh()->toAsciiTree();
        $this->assertStringContainsString('│   └── C', $tree);
    }

    #[Test]
    public function ascii_tree_show_depth(): void
    {
        [$electronics, , , $iphone] = $this->buildElectronicsTree();

        $tree = $electronics->toAsciiTree(new AsciiOptions(showDepth: true));

        $this->assertStringContainsString('Electronics (depth=0)', $tree);
        $this->assertStringContainsString('iPhone (depth=2)', $tree);
    }

    #[Test]
    public function ascii_tree_max_depth_truncates(): void
    {
        [$electronics] = $this->buildElectronicsTree();

        $tree = $electronics->toAsciiTree(new AsciiOptions(maxDepth: 1));

        $this->assertStringContainsString('Laptops', $tree);
        $this->assertStringContainsString('Phones', $tree);
        $this->assertStringNotContainsString('iPhone', $tree);
        $this->assertStringNotContainsString('Android', $tree);
    }

    #[Test]
    public function mermaid_emits_nodes_and_edges(): void
    {
        [$electronics, $laptops, $phones, $iphone, $android] = $this->buildElectronicsTree();

        $mermaid = $electronics->toMermaid();

        $this->assertStringStartsWith('graph TD', $mermaid);
        $this->assertStringContainsString("    n{$electronics->id}[\"Electronics\"]", $mermaid);
        $this->assertStringContainsString("    n{$laptops->id}[\"Laptops\"]", $mermaid);
        $this->assertStringContainsString("    n{$electronics->id} --> n{$laptops->id}", $mermaid);
        $this->assertStringContainsString("    n{$electronics->id} --> n{$phones->id}", $mermaid);
        $this->assertStringContainsString("    n{$phones->id} --> n{$iphone->id}", $mermaid);
        $this->assertStringContainsString("    n{$phones->id} --> n{$android->id}", $mermaid);
    }

    #[Test]
    public function mermaid_direction_option(): void
    {
        [$electronics] = $this->buildElectronicsTree();

        $mermaid = $electronics->toMermaid(new MermaidOptions(direction: 'LR'));

        $this->assertStringStartsWith('graph LR', $mermaid);
    }

    #[Test]
    public function mermaid_show_id_appends_pk(): void
    {
        [$electronics] = $this->buildElectronicsTree();

        $mermaid = $electronics->toMermaid(new MermaidOptions(showId: true));

        $this->assertStringContainsString("Electronics (id={$electronics->id})", $mermaid);
    }

    #[Test]
    public function mermaid_escapes_special_characters_in_label(): void
    {
        $root = new Category(['name' => 'A & B "C" <D>']);
        $root->saveAsRoot();

        $mermaid = $root->refresh()->toMermaid();

        $this->assertStringContainsString('&quot;C&quot;', $mermaid);
        $this->assertStringContainsString('&lt;D&gt;', $mermaid);
    }

    #[Test]
    public function mermaid_falls_back_to_pk_when_label_closure_throws(): void
    {
        [$electronics] = $this->buildElectronicsTree();

        $mermaid = $electronics->toMermaid(new MermaidOptions(
            label: static function (): string {
                throw new \RuntimeException('label boom');
            },
        ));

        $this->assertStringContainsString("[\"{$electronics->id}\"]", $mermaid);
    }

    #[Test]
    public function dot_emits_digraph(): void
    {
        [$electronics, $laptops, , $iphone] = $this->buildElectronicsTree();

        $dot = $electronics->toDot();

        $this->assertStringStartsWith('digraph tree {', $dot);
        $this->assertStringContainsString('rankdir=TB;', $dot);
        $this->assertStringContainsString('node [shape=box];', $dot);
        $this->assertStringContainsString("\"n{$electronics->id}\" [label=\"Electronics\"];", $dot);
        $this->assertStringContainsString("\"n{$laptops->id}\" [label=\"Laptops\"];", $dot);
        $this->assertStringContainsString("\"n{$electronics->id}\" -> \"n{$laptops->id}\";", $dot);
        $this->assertStringContainsString('[label="iPhone"]', $dot);
        $this->assertStringEndsWith('}', $dot);
        $this->assertStringNotContainsString('iPhone (depth=', $dot);
    }

    #[Test]
    public function dot_direction_option(): void
    {
        [$electronics] = $this->buildElectronicsTree();

        $dot = $electronics->toDot(new DotOptions(direction: 'LR'));

        $this->assertStringContainsString('rankdir=LR;', $dot);
    }

    #[Test]
    public function dot_escapes_quotes_and_backslashes(): void
    {
        $root = new Category(['name' => 'path\\to "x"']);
        $root->saveAsRoot();

        $dot = $root->refresh()->toDot();

        $this->assertStringContainsString('label="path\\\\to \\"x\\""', $dot);
    }

    #[Test]
    public function json_tree_shape(): void
    {
        [$electronics, $laptops, $phones, $iphone, $android] = $this->buildElectronicsTree();

        $json = $electronics->toJsonTree();

        $this->assertSame([
            'id' => $electronics->id,
            'label' => 'Electronics',
            'children' => [
                [
                    'id' => $laptops->id,
                    'label' => 'Laptops',
                    'children' => [],
                ],
                [
                    'id' => $phones->id,
                    'label' => 'Phones',
                    'children' => [
                        [
                            'id' => $iphone->id,
                            'label' => 'iPhone',
                            'children' => [],
                        ],
                        [
                            'id' => $android->id,
                            'label' => 'Android',
                            'children' => [],
                        ],
                    ],
                ],
            ],
        ], $json);
    }

    #[Test]
    public function json_tree_extras_and_children_key(): void
    {
        [$electronics] = $this->buildElectronicsTree();

        $json = $electronics->toJsonTree(new JsonOptions(
            extras: ['lft', 'rgt', 'depth'],
            childrenKey: 'items',
        ));

        $this->assertArrayHasKey('items', $json);
        $this->assertArrayHasKey('lft', $json);
        $this->assertArrayHasKey('rgt', $json);
        $this->assertSame(0, $json['depth']);
        $this->assertArrayNotHasKey('children', $json);
    }

    #[Test]
    public function json_tree_custom_label_closure(): void
    {
        [$electronics] = $this->buildElectronicsTree();

        $json = $electronics->toJsonTree(new JsonOptions(
            label: static function ($n): string {
                $name = $n->getAttribute('name');

                return strtoupper(is_string($name) ? $name : '');
            },
        ));

        $this->assertSame('ELECTRONICS', $json['label']);
    }

    #[Test]
    public function json_label_closure_stringifies_numeric_return_values(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $root = $root->refresh();

        // A numeric label (int or float — both in the closure's declared
        // return type) is normalised to its string form.
        $this->assertSame('42', $root->toJsonTree(new JsonOptions(label: static fn (): int => 42))['label']);
        $this->assertSame('3.5', $root->toJsonTree(new JsonOptions(label: static fn (): float => 3.5))['label']);
    }

    #[Test]
    public function subtree_export_omits_siblings_of_root(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();

        $other = new Category(['name' => 'Other']);
        $other->saveAsRoot();

        [$electronics] = $this->buildElectronicsTree();

        $mermaid = $electronics->toMermaid();

        $this->assertStringNotContainsString('Root', $mermaid);
        $this->assertStringNotContainsString('Other', $mermaid);
    }

    #[Test]
    public function forest_exports_every_root(): void
    {
        $a = new Category(['name' => 'A']);
        $a->saveAsRoot();
        $b = new Category(['name' => 'B']);
        $b->saveAsRoot();

        $mermaid = Category::toMermaidForest();

        $this->assertStringContainsString('"A"', $mermaid);
        $this->assertStringContainsString('"B"', $mermaid);
    }

    #[Test]
    public function scope_export_filters_to_one_tree(): void
    {
        $menu1 = Menu::create(['name' => 'Menu 1']);
        $menu2 = Menu::create(['name' => 'Menu 2']);

        $m1 = new MenuItem(['name' => 'M1-root', 'menu_id' => $menu1->id]);
        $m1->saveAsRoot();
        $m1Child = new MenuItem(['name' => 'M1-child', 'menu_id' => $menu1->id]);
        $m1Child->appendToNode($m1->refresh())->save();

        $m2 = new MenuItem(['name' => 'M2-root', 'menu_id' => $menu2->id]);
        $m2->saveAsRoot();

        $ascii = MenuItem::toAsciiTreeScope($menu1->id);

        $this->assertStringContainsString('M1-root', $ascii);
        $this->assertStringContainsString('M1-child', $ascii);
        $this->assertStringNotContainsString('M2-root', $ascii);
    }

    #[Test]
    public function scope_export_rejects_unscoped_models(): void
    {
        $this->expectException(ScopeViolationException::class);

        Category::toAsciiTreeScope(1);
    }

    #[Test]
    public function uuid_pk_uses_hashed_node_id(): void
    {
        $root = new UuidTag(['name' => 'Root']);
        $root->saveAsRoot();
        $root = $root->refresh();

        $mermaid = $root->toMermaid();

        $this->assertMatchesRegularExpression('/n[a-f0-9]{8}\["Root"\]/', $mermaid);
        $key = $root->getKey();
        $this->assertIsString($key);
        $this->assertStringNotContainsString($key, $mermaid);
    }

    #[Test]
    public function soft_deleted_descendants_excluded_by_default(): void
    {
        [$electronics, , $phones, , $android] = $this->buildElectronicsTree();
        $android->delete();

        $ascii = $electronics->refresh()->toAsciiTree();

        $this->assertStringNotContainsString('Android', $ascii);

        $this->allowBrokenTreeAtTearDown = false;
    }

    #[Test]
    public function soft_deleted_descendants_included_with_with_trashed(): void
    {
        [$electronics, , , , $android] = $this->buildElectronicsTree();
        $android->delete();

        $ascii = $electronics->refresh()->toAsciiTree(new AsciiOptions(withTrashed: true));

        $this->assertStringContainsString('Android', $ascii);
    }

    #[Test]
    public function cycle_detection_throws_corrupt_tree_exception(): void
    {
        $this->allowBrokenTreeAtTearDown = true;

        [$electronics, $laptops, $phones] = $this->buildElectronicsTree();

        // Engineer a parent_id cycle by mutating attributes directly,
        // skipping the mutation API that would refuse to write it.
        \DB::table('categories')->where('id', $electronics->id)->update(['parent_id' => $phones->id]);

        $this->expectException(CorruptTreeException::class);

        Category::toMermaidForest();
    }

    #[Test]
    public function aggregates_in_label_when_show_aggregates_set(): void
    {
        [$electronics] = $this->buildElectronicsTree();

        // Use an attribute we can stub via setAttribute — Category doesn't
        // declare an aggregate column, so simulate by setting a raw attribute
        // and reading it via showAggregates.
        $electronics->setAttribute('products_total', 23);

        $mermaid = $electronics->toMermaid(new MermaidOptions(showAggregates: ['products_total']));

        $this->assertStringContainsString('Electronics<br/>products_total: 23', $mermaid);
    }

    #[Test]
    public function mermaid_options_rejects_invalid_direction(): void
    {
        // Reflection bypasses the static narrow ('TD'|'LR'|'BT'|'RL'), so
        // the runtime guard is what's actually under test here.
        $this->expectException(\InvalidArgumentException::class);

        (new \ReflectionClass(MermaidOptions::class))->newInstance('XX');
    }

    #[Test]
    public function dot_options_rejects_invalid_direction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new \ReflectionClass(DotOptions::class))->newInstance('TD');
    }

    #[Test]
    public function aggregate_boolean_renders_as_true_false_not_empty_string(): void
    {
        [$electronics] = $this->buildElectronicsTree();
        $electronics->setAttribute('featured', false);

        $mermaid = $electronics->toMermaid(new MermaidOptions(showAggregates: ['featured']));

        $this->assertStringContainsString('featured: false', $mermaid);
        $this->assertStringNotContainsString('featured: <br/>', $mermaid);
    }

    #[Test]
    public function mermaid_label_with_newline_renders_as_br(): void
    {
        $root = new Category(['name' => "Line1\nLine2"]);
        $root->saveAsRoot();

        $mermaid = $root->refresh()->toMermaid();

        $this->assertStringContainsString('Line1<br/>Line2', $mermaid);
    }

    #[Test]
    public function subtree_export_of_scoped_model_only_includes_same_scope(): void
    {
        // Two trees with overlapping lft/rgt (each scope restarts its
        // sequence at 1). Without the scope filter on the descendants
        // query, rows from the other tree would leak in.
        $menu1 = Menu::create(['name' => 'M1']);
        $menu2 = Menu::create(['name' => 'M2']);

        $r1 = new MenuItem(['name' => 'M1-Root', 'menu_id' => $menu1->id]);
        $r1->saveAsRoot();
        $c1 = new MenuItem(['name' => 'M1-Child', 'menu_id' => $menu1->id]);
        $c1->appendToNode($r1->refresh())->save();

        $r2 = new MenuItem(['name' => 'M2-Root', 'menu_id' => $menu2->id]);
        $r2->saveAsRoot();
        $c2 = new MenuItem(['name' => 'M2-Child', 'menu_id' => $menu2->id]);
        $c2->appendToNode($r2->refresh())->save();

        $ascii = $r1->refresh()->toAsciiTree();

        $this->assertStringContainsString('M1-Root', $ascii);
        $this->assertStringContainsString('M1-Child', $ascii);
        $this->assertStringNotContainsString('M2-Root', $ascii);
        $this->assertStringNotContainsString('M2-Child', $ascii);
    }

    #[Test]
    public function forest_groups_scoped_trees_by_scope_column(): void
    {
        // Auto-increment guarantees menu1.id < menu2.id even though we
        // insert menu2's tree first. The Forest loader's `ORDER BY
        // menu_id, lft` must put menu1's tree first in the output;
        // without it, insert order would render menu2 first.
        $menu1 = Menu::create(['name' => 'M1']);
        $menu2 = Menu::create(['name' => 'M2']);

        $r2 = new MenuItem(['name' => 'M2-Root', 'menu_id' => $menu2->id]);
        $r2->saveAsRoot();

        $r1 = new MenuItem(['name' => 'M1-Root', 'menu_id' => $menu1->id]);
        $r1->saveAsRoot();
        $c1 = new MenuItem(['name' => 'M1-Child', 'menu_id' => $menu1->id]);
        $c1->appendToNode($r1->refresh())->save();

        $ascii = MenuItem::toAsciiTreeForest();

        $m1Root = strpos($ascii, 'M1-Root');
        $m1Child = strpos($ascii, 'M1-Child');
        $m2Root = strpos($ascii, 'M2-Root');

        $this->assertNotFalse($m1Root);
        $this->assertNotFalse($m1Child);
        $this->assertNotFalse($m2Root);
        $this->assertLessThan($m2Root, $m1Root, 'menu1 tree must render before menu2 tree (scope grouping).');
        $this->assertLessThan($m1Child, $m1Root, 'within menu1, root must render before child (lft order).');
    }

    #[Test]
    public function dot_forest_renders_digraph(): void
    {
        $a = new Category(['name' => 'A']);
        $a->saveAsRoot();
        $b = new Category(['name' => 'B']);
        $b->saveAsRoot();

        $dot = Category::toDotForest();

        $this->assertStringStartsWith('digraph tree {', $dot);
        $this->assertStringContainsString('label="A"', $dot);
        $this->assertStringContainsString('label="B"', $dot);
    }

    #[Test]
    public function ascii_tree_forest_renders_each_root(): void
    {
        $a = new Category(['name' => 'A']);
        $a->saveAsRoot();
        $b = new Category(['name' => 'B']);
        $b->saveAsRoot();

        $ascii = Category::toAsciiTreeForest();

        $this->assertStringContainsString('A', $ascii);
        $this->assertStringContainsString('B', $ascii);
    }

    #[Test]
    public function json_tree_forest_returns_list_for_multiple_roots(): void
    {
        $a = new Category(['name' => 'A']);
        $a->saveAsRoot();
        $b = new Category(['name' => 'B']);
        $b->saveAsRoot();

        $json = Category::toJsonTreeForest();

        $this->assertSame([
            ['id' => $a->refresh()->id, 'label' => 'A', 'children' => []],
            ['id' => $b->refresh()->id, 'label' => 'B', 'children' => []],
        ], $json);
    }

    #[Test]
    public function dot_scope_filters_to_one_tree(): void
    {
        $menu1 = Menu::create(['name' => 'M1']);
        $menu2 = Menu::create(['name' => 'M2']);
        (new MenuItem(['name' => 'M1-Root', 'menu_id' => $menu1->id]))->saveAsRoot();
        (new MenuItem(['name' => 'M2-Root', 'menu_id' => $menu2->id]))->saveAsRoot();

        $dot = MenuItem::toDotScope($menu1->id);

        $this->assertStringContainsString('M1-Root', $dot);
        $this->assertStringNotContainsString('M2-Root', $dot);
    }

    #[Test]
    public function json_tree_scope_filters_to_one_tree(): void
    {
        $menu1 = Menu::create(['name' => 'M1']);
        $menu2 = Menu::create(['name' => 'M2']);
        (new MenuItem(['name' => 'M1-Root', 'menu_id' => $menu1->id]))->saveAsRoot();
        (new MenuItem(['name' => 'M2-Root', 'menu_id' => $menu2->id]))->saveAsRoot();

        $json = MenuItem::toJsonTreeScope($menu1->id);

        $this->assertArrayHasKey('label', $json);
        $this->assertSame('M1-Root', $json['label']);
    }

    #[Test]
    public function mermaid_scope_filters_to_one_tree(): void
    {
        $menu1 = Menu::create(['name' => 'M1']);
        $menu2 = Menu::create(['name' => 'M2']);
        (new MenuItem(['name' => 'M1-Root', 'menu_id' => $menu1->id]))->saveAsRoot();
        (new MenuItem(['name' => 'M2-Root', 'menu_id' => $menu2->id]))->saveAsRoot();

        $mermaid = MenuItem::toMermaidScope($menu1->id);

        $this->assertStringContainsString('"M1-Root"', $mermaid);
        $this->assertStringNotContainsString('"M2-Root"', $mermaid);
    }

    #[Test]
    public function mermaid_options_default_excludes_trashed(): void
    {
        [$electronics, , , , $android] = $this->buildElectronicsTree();
        $android->delete();

        $mermaid = $electronics->refresh()->toMermaid(new MermaidOptions);

        $this->assertStringNotContainsString('"Android"', $mermaid);
    }

    #[Test]
    public function dot_options_default_excludes_trashed(): void
    {
        [$electronics, , , , $android] = $this->buildElectronicsTree();
        $android->delete();

        $dot = $electronics->refresh()->toDot(new DotOptions);

        $this->assertStringNotContainsString('"Android"', $dot);
    }

    #[Test]
    public function json_tree_options_default_excludes_trashed(): void
    {
        [$electronics, , , $iphone, $android] = $this->buildElectronicsTree();
        $android->delete();

        $json = $electronics->refresh()->toJsonTree(new JsonOptions);

        $encoded = json_encode($json);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('Android', $encoded);
        $this->assertStringContainsString('iPhone', $encoded);
    }

    #[Test]
    public function forest_with_trashed_includes_soft_deleted_rows(): void
    {
        [, , , , $android] = $this->buildElectronicsTree();
        $android->delete();

        $withoutTrashed = Category::toAsciiTreeForest();
        $withTrashed = Category::toAsciiTreeForest(new AsciiOptions(withTrashed: true));

        $this->assertStringNotContainsString('Android', $withoutTrashed);
        $this->assertStringContainsString('Android', $withTrashed);
    }

    #[Test]
    public function ascii_tree_max_depth_at_2_renders_grandchildren_only(): void
    {
        $r = new Category(['name' => 'R']);
        $r->saveAsRoot();
        $a = new Category(['name' => 'A']);
        $a->appendToNode($r->refresh())->save();
        $aa = new Category(['name' => 'AA']);
        $aa->appendToNode($a->refresh())->save();
        $aaa = new Category(['name' => 'AAA']);
        $aaa->appendToNode($aa->refresh())->save();

        $ascii = $r->refresh()->toAsciiTree(new AsciiOptions(maxDepth: 2));

        $this->assertStringContainsString('R', $ascii);
        $this->assertStringContainsString('A', $ascii);
        $this->assertStringContainsString('AA', $ascii);
        $this->assertStringNotContainsString('AAA', $ascii);
    }

    #[Test]
    public function dot_show_aggregates_renders_columns(): void
    {
        [$electronics] = $this->buildElectronicsTree();
        $electronics->setAttribute('products_total', 23);

        $dot = $electronics->toDot(new DotOptions(showAggregates: ['products_total']));

        // DOT uses literal `\n` between label segments.
        $this->assertStringContainsString('Electronics\\nproducts_total: 23', $dot);
    }

    #[Test]
    public function aggregate_float_value_renders_in_label(): void
    {
        [$electronics] = $this->buildElectronicsTree();
        $electronics->setAttribute('price_avg', 12.5);

        $mermaid = $electronics->toMermaid(new MermaidOptions(showAggregates: ['price_avg']));

        $this->assertStringContainsString('price_avg: 12.5', $mermaid);
    }

    #[Test]
    public function filter_prunes_nodes_and_edges_uniformly_across_formats(): void
    {
        // All four exporters share one filter primitive — they should
        // produce the same node/edge inclusion decision when handed the
        // same predicate. Here we exclude the Phones subtree (Phones +
        // iPhone + Android) and assert it disappears from every format.
        [$electronics] = $this->buildElectronicsTree();

        $excludePhones = WalkFilter::where(
            static fn (\Illuminate\Database\Eloquent\Model&HasNestedSet $n): bool => $n->getAttribute('name') !== 'Phones',
        );

        $mermaid = $electronics->toMermaid(new MermaidOptions(filter: $excludePhones));
        $this->assertStringContainsString('Electronics', $mermaid);
        $this->assertStringContainsString('Laptops', $mermaid);
        $this->assertStringNotContainsString('Phones', $mermaid);
        $this->assertStringNotContainsString('iPhone', $mermaid);
        $this->assertStringNotContainsString('Android', $mermaid);

        $dot = $electronics->toDot(new DotOptions(filter: $excludePhones));
        $this->assertStringContainsString('Electronics', $dot);
        $this->assertStringContainsString('Laptops', $dot);
        $this->assertStringNotContainsString('Phones', $dot);
        $this->assertStringNotContainsString('iPhone', $dot);

        $ascii = $electronics->toAsciiTree(new AsciiOptions(filter: $excludePhones));
        $this->assertSame("Electronics\n└── Laptops", $ascii);

        $json = $electronics->toJsonTree(new JsonOptions(filter: $excludePhones));
        // toJsonTree returns an associative payload for a single root and
        // a list of payloads for a forest. Single-root path here.
        $this->assertSame('Electronics', $json['label']);
        $children = $json['children'];
        $this->assertIsArray($children);
        $childLabels = array_map(static fn (mixed $c): mixed => is_array($c) ? $c['label'] ?? null : null, $children);
        $this->assertSame(['Laptops'], $childLabels);
    }

    #[Test]
    public function uuid_node_id_is_deterministic_hash_of_key(): void
    {
        // Hash offset matters: substr(md5(...), 0, 8) vs substr(..., 1, 8)
        // produce different but both-valid-hex strings, so the regex test
        // can't tell them apart. Assert the exact computed value to pin
        // the offset. Use a real UUID literal — Postgres types the
        // uuid_tags.id column as UUID and rejects arbitrary strings.
        $fixedUuid = '0192f6c3-1234-7abc-8def-0123456789ab';
        $root = new UuidTag(['name' => 'Root']);
        $root->id = $fixedUuid;
        $root->saveAsRoot();

        $mermaid = $root->refresh()->toMermaid();

        $expected = 'n'.substr(md5($fixedUuid), 0, 8);
        $this->assertStringContainsString("{$expected}[\"Root\"]", $mermaid);
    }
}
