<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\MaterialisedPath;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\ScopedSluggedMenuItem;
use Vusys\NestedSet\Tests\TestCase;

final class MaterialisedPathScopedTest extends TestCase
{
    #[Test]
    public function paths_are_scoped_per_partition(): void
    {
        $rootA = new ScopedSluggedMenuItem(['menu_id' => 1, 'name' => 'Root']);
        $rootA->makeRoot()->save();

        $rootB = new ScopedSluggedMenuItem(['menu_id' => 2, 'name' => 'Root']);
        $rootB->makeRoot()->save();

        $rootA->refresh();
        $rootB->refresh();
        $this->assertSame('/root/', $rootA->url_path);
        $this->assertSame('/root/', $rootB->url_path);
    }

    #[Test]
    public function rename_only_rewrites_within_the_same_scope(): void
    {
        $rootA = new ScopedSluggedMenuItem(['menu_id' => 1, 'name' => 'Root']);
        $rootA->makeRoot()->save();
        $childA = new ScopedSluggedMenuItem(['menu_id' => 1, 'name' => 'Child']);
        $childA->appendToNode($rootA)->save();

        $rootB = new ScopedSluggedMenuItem(['menu_id' => 2, 'name' => 'Root']);
        $rootB->makeRoot()->save();
        $childB = new ScopedSluggedMenuItem(['menu_id' => 2, 'name' => 'Child']);
        $childB->appendToNode($rootB)->save();

        $rootA->name = 'Renamed';
        $rootA->save();

        $childA->refresh();
        $childB->refresh();
        $this->assertSame('/renamed/child/', $childA->url_path);
        $this->assertSame('/root/child/', $childB->url_path, 'scope B untouched');
    }
}
