<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Export;

use Vusys\NestedSet\Exceptions\CorruptTreeException;
use Vusys\NestedSet\Export\AsciiOptions;
use Vusys\NestedSet\Export\DotOptions;
use Vusys\NestedSet\Export\JsonOptions;
use Vusys\NestedSet\Export\MermaidOptions;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\Fixtures\Models\Menu;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\Fixtures\Models\UuidTag;
use Vusys\NestedSet\Tests\TestCase;

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

    public function test_ascii_tree_unicode_default(): void
    {
        [$electronics] = $this->buildElectronicsTree();

        $expected = "Electronics\n"
            ."├── Laptops\n"
            ."└── Phones\n"
            ."    ├── iPhone\n"
            .'    └── Android';

        $this->assertSame($expected, $electronics->toAsciiTree());
    }

    public function test_ascii_tree_plain_ascii_fallback(): void
    {
        [$electronics] = $this->buildElectronicsTree();

        $expected = "Electronics\n"
            ."|-- Laptops\n"
            ."`-- Phones\n"
            ."    |-- iPhone\n"
            .'    `-- Android';

        $this->assertSame($expected, $electronics->toAsciiTree(new AsciiOptions(unicode: false)));
    }

    public function test_ascii_tree_renders_vertical_continuation_for_deep_nodes(): void
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

    public function test_ascii_tree_show_depth(): void
    {
        [$electronics, , , $iphone] = $this->buildElectronicsTree();

        $tree = $electronics->toAsciiTree(new AsciiOptions(showDepth: true));

        $this->assertStringContainsString('Electronics (depth=0)', $tree);
        $this->assertStringContainsString('iPhone (depth=2)', $tree);
    }

    public function test_ascii_tree_max_depth_truncates(): void
    {
        [$electronics] = $this->buildElectronicsTree();

        $tree = $electronics->toAsciiTree(new AsciiOptions(maxDepth: 1));

        $this->assertStringContainsString('Laptops', $tree);
        $this->assertStringContainsString('Phones', $tree);
        $this->assertStringNotContainsString('iPhone', $tree);
        $this->assertStringNotContainsString('Android', $tree);
    }

    public function test_mermaid_emits_nodes_and_edges(): void
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

    public function test_mermaid_direction_option(): void
    {
        [$electronics] = $this->buildElectronicsTree();

        $mermaid = $electronics->toMermaid(new MermaidOptions(direction: 'LR'));

        $this->assertStringStartsWith('graph LR', $mermaid);
    }

    public function test_mermaid_show_id_appends_pk(): void
    {
        [$electronics] = $this->buildElectronicsTree();

        $mermaid = $electronics->toMermaid(new MermaidOptions(showId: true));

        $this->assertStringContainsString("Electronics (id={$electronics->id})", $mermaid);
    }

    public function test_mermaid_escapes_special_characters_in_label(): void
    {
        $root = new Category(['name' => 'A & B "C" <D>']);
        $root->saveAsRoot();

        $mermaid = $root->refresh()->toMermaid();

        $this->assertStringContainsString('&quot;C&quot;', $mermaid);
        $this->assertStringContainsString('&lt;D&gt;', $mermaid);
    }

    public function test_mermaid_falls_back_to_pk_when_label_closure_throws(): void
    {
        [$electronics] = $this->buildElectronicsTree();

        $mermaid = $electronics->toMermaid(new MermaidOptions(
            label: static function (): string {
                throw new \RuntimeException('label boom');
            },
        ));

        $this->assertStringContainsString("[\"{$electronics->id}\"]", $mermaid);
    }

    public function test_dot_emits_digraph(): void
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

    public function test_dot_direction_option(): void
    {
        [$electronics] = $this->buildElectronicsTree();

        $dot = $electronics->toDot(new DotOptions(direction: 'LR'));

        $this->assertStringContainsString('rankdir=LR;', $dot);
    }

    public function test_dot_escapes_quotes_and_backslashes(): void
    {
        $root = new Category(['name' => 'path\\to "x"']);
        $root->saveAsRoot();

        $dot = $root->refresh()->toDot();

        $this->assertStringContainsString('label="path\\\\to \\"x\\""', $dot);
    }

    public function test_json_tree_shape(): void
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

    public function test_json_tree_extras_and_children_key(): void
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

    public function test_json_tree_custom_label_closure(): void
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

    public function test_subtree_export_omits_siblings_of_root(): void
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

    public function test_forest_exports_every_root(): void
    {
        $a = new Category(['name' => 'A']);
        $a->saveAsRoot();
        $b = new Category(['name' => 'B']);
        $b->saveAsRoot();

        $mermaid = Category::toMermaidForest();

        $this->assertStringContainsString('"A"', $mermaid);
        $this->assertStringContainsString('"B"', $mermaid);
    }

    public function test_scope_export_filters_to_one_tree(): void
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

    public function test_scope_export_rejects_unscoped_models(): void
    {
        $this->expectException(\LogicException::class);

        Category::toAsciiTreeScope(1);
    }

    public function test_uuid_pk_uses_hashed_node_id(): void
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

    public function test_soft_deleted_descendants_excluded_by_default(): void
    {
        [$electronics, , $phones, , $android] = $this->buildElectronicsTree();
        $android->delete();

        $ascii = $electronics->refresh()->toAsciiTree();

        $this->assertStringNotContainsString('Android', $ascii);

        $this->allowBrokenTreeAtTearDown = false;
    }

    public function test_soft_deleted_descendants_included_with_with_trashed(): void
    {
        [$electronics, , , , $android] = $this->buildElectronicsTree();
        $android->delete();

        $ascii = $electronics->refresh()->toAsciiTree(new AsciiOptions(withTrashed: true));

        $this->assertStringContainsString('Android', $ascii);
    }

    public function test_cycle_detection_throws_corrupt_tree_exception(): void
    {
        $this->allowBrokenTreeAtTearDown = true;

        [$electronics, $laptops, $phones] = $this->buildElectronicsTree();

        // Engineer a parent_id cycle by mutating attributes directly,
        // skipping the mutation API that would refuse to write it.
        \DB::table('categories')->where('id', $electronics->id)->update(['parent_id' => $phones->id]);

        $this->expectException(CorruptTreeException::class);

        Category::toMermaidForest();
    }

    public function test_aggregates_in_label_when_show_aggregates_set(): void
    {
        [$electronics] = $this->buildElectronicsTree();

        // Use an attribute we can stub via setAttribute — Category doesn't
        // declare an aggregate column, so simulate by setting a raw attribute
        // and reading it via showAggregates.
        $electronics->setAttribute('products_total', 23);

        $mermaid = $electronics->toMermaid(new MermaidOptions(showAggregates: ['products_total']));

        $this->assertStringContainsString('Electronics<br/>products_total: 23', $mermaid);
    }

    public function test_mermaid_options_rejects_invalid_direction(): void
    {
        // Reflection bypasses the static narrow ('TD'|'LR'|'BT'|'RL'), so
        // the runtime guard is what's actually under test here.
        $this->expectException(\InvalidArgumentException::class);

        (new \ReflectionClass(MermaidOptions::class))->newInstance('XX');
    }

    public function test_dot_options_rejects_invalid_direction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new \ReflectionClass(DotOptions::class))->newInstance('TD');
    }

    public function test_aggregate_boolean_renders_as_true_false_not_empty_string(): void
    {
        [$electronics] = $this->buildElectronicsTree();
        $electronics->setAttribute('featured', false);

        $mermaid = $electronics->toMermaid(new MermaidOptions(showAggregates: ['featured']));

        $this->assertStringContainsString('featured: false', $mermaid);
        $this->assertStringNotContainsString('featured: <br/>', $mermaid);
    }

    public function test_mermaid_label_with_newline_renders_as_br(): void
    {
        $root = new Category(['name' => "Line1\nLine2"]);
        $root->saveAsRoot();

        $mermaid = $root->refresh()->toMermaid();

        $this->assertStringContainsString('Line1<br/>Line2', $mermaid);
    }
}
