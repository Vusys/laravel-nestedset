<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\MaterialisedPath;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\KeyPathCategory;
use Vusys\NestedSet\Tests\Fixtures\Models\MultiPathCategory;
use Vusys\NestedSet\Tests\Fixtures\Models\SluggedCategory;
use Vusys\NestedSet\Tests\TestCase;

final class MaterialisedPathBulkInsertTest extends TestCase
{
    #[Test]
    public function bulk_insert_populates_paths_on_every_row(): void
    {
        $root = new SluggedCategory(['name' => 'Root']);
        $root->makeRoot()->save();

        SluggedCategory::bulkInsertTree([
            [
                'name' => 'Branch',
                'children' => [
                    ['name' => 'Leaf-1'],
                    ['name' => 'Leaf-2'],
                ],
            ],
        ], $root->refresh());

        $byName = [];
        foreach (SluggedCategory::query()->orderBy('lft')->get() as $row) {
            $byName[$row->name] = $row->url_path;
        }
        $this->assertSame('/root/', $byName['Root']);
        $this->assertSame('/root/branch/', $byName['Branch']);
        $this->assertSame('/root/branch/leaf-1/', $byName['Leaf-1']);
        $this->assertSame('/root/branch/leaf-2/', $byName['Leaf-2']);
    }

    #[Test]
    public function bulk_insert_populates_key_dependent_paths(): void
    {
        $root = new KeyPathCategory(['name' => 'R']);
        $root->makeRoot()->save();

        KeyPathCategory::bulkInsertTree([
            ['name' => 'Child', 'children' => [['name' => 'Grand']]],
        ], $root->refresh());

        /** @var array<string, KeyPathCategory> $rows */
        $rows = [];
        foreach (KeyPathCategory::query()->orderBy('lft')->get() as $row) {
            $rows[$row->name] = $row;
        }
        $this->assertSame('.'.$rows['R']->id.'.', $rows['R']->id_path);
        $this->assertSame('.'.$rows['R']->id.'.'.$rows['Child']->id.'.', $rows['Child']->id_path);
        $this->assertSame(
            '.'.$rows['R']->id.'.'.$rows['Child']->id.'.'.$rows['Grand']->id.'.',
            $rows['Grand']->id_path,
        );
    }

    #[Test]
    public function bulk_insert_multi_path_keeps_each_column_independent(): void
    {
        $root = new MultiPathCategory(['name' => 'Electronics', 'display_name' => 'Electronics']);
        $root->makeRoot()->save();

        MultiPathCategory::bulkInsertTree([
            ['name' => 'Laptops', 'display_name' => 'Laptops & Notebooks'],
        ], $root->refresh());

        $child = MultiPathCategory::query()->where('name', 'Laptops')->first();
        $this->assertNotNull($child);
        $this->assertSame('/electronics/laptops/', $child->url_path);
        $this->assertSame('Electronics > Laptops & Notebooks', $child->crumb_path);
    }
}
