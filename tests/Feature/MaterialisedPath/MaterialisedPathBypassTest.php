<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\MaterialisedPath;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\SluggedCategory;
use Vusys\NestedSet\Tests\TestCase;

final class MaterialisedPathBypassTest extends TestCase
{
    #[Test]
    public function without_maintenance_skips_path_writes(): void
    {
        $root = new SluggedCategory(['name' => 'Root']);
        $root->makeRoot()->save();
        $root->refresh();
        $this->assertSame('/root/', $root->url_path);

        SluggedCategory::withoutMaterialisedPathMaintenance(function () use ($root): void {
            $root->name = 'Renamed';
            $root->save();
        });
        $root->refresh();
        $this->assertSame('/root/', $root->url_path);
        $this->assertSame('Renamed', $root->name);
    }

    #[Test]
    public function follow_up_fix_restores_paths(): void
    {
        $root = new SluggedCategory(['name' => 'Root']);
        $root->makeRoot()->save();
        $root->refresh();

        SluggedCategory::withoutMaterialisedPathMaintenance(function () use ($root): void {
            $root->name = 'Renamed';
            $root->save();
        });

        $repaired = SluggedCategory::fixMaterialisedPaths();
        $this->assertArrayHasKey('url_path', $repaired);
        $this->assertGreaterThan(0, $repaired['url_path']);

        $root->refresh();
        $this->assertSame('/renamed/', $root->url_path);
    }
}
