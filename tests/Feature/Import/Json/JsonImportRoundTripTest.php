<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Import\Json;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Export\JsonOptions;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Round-trip: `toJsonTree` → `fromJsonTree` reproduces the same
 * structural shape (parent wiring + sibling order). Primary keys and
 * tree-encoding columns regenerate per design §6.5 of the round-trip
 * spec.
 */
final class JsonImportRoundTripTest extends TestCase
{
    #[Test]
    public function export_then_import_produces_same_structure(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->makeRoot()->save();
        $a = new Category(['name' => 'A']);
        $a->appendToNode($root)->save();
        $b = new Category(['name' => 'B']);
        $b->appendToNode($root)->save();
        $aa = new Category(['name' => 'AA']);
        $aa->appendToNode($a)->save();

        $json = Category::toJsonTreeForest(new JsonOptions(extras: ['name']));

        Category::query()->forceDelete();

        Category::fromJsonTree($json);

        $names = Category::query()->orderBy('lft')->pluck('name')->all();
        $this->assertSame(['Root', 'A', 'AA', 'B'], $names);

        $depths = Category::query()->orderBy('lft')->pluck('depth')->all();
        $this->assertSame([0, 1, 2, 1], $depths);
    }

    #[Test]
    public function default_round_trip_maps_label_back_to_name(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->makeRoot()->save();
        $a = new Category(['name' => 'A']);
        $a->appendToNode($root->refresh())->save();

        // No extras: the default exporter emits `label` (from name) only.
        // fromJsonTree must map it back onto the `name` column by default,
        // not drop it and hit the NOT NULL constraint.
        $json = Category::toJsonTreeForest();

        Category::query()->forceDelete();

        Category::fromJsonTree($json);

        $names = Category::query()->orderBy('lft')->pluck('name')->all();
        $this->assertSame(['Root', 'A'], $names);
    }
}
