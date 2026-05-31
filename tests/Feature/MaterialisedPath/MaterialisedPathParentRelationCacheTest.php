<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\MaterialisedPath;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Tests\Fixtures\Models\SluggedCategory;
use Vusys\NestedSet\Tests\TestCase;

final class MaterialisedPathParentRelationCacheTest extends TestCase
{
    public function test_eager_loaded_parent_skips_the_db_lookup(): void
    {
        $root = new SluggedCategory(['name' => 'Root']);
        $root->makeRoot()->save();
        $child = new SluggedCategory(['name' => 'Child']);
        $child->appendToNode($root)->save();

        // Reload with the parent relation populated; the saving listener
        // should read the parent's url_path off the in-memory relation
        // instead of issuing a SELECT.
        $loaded = SluggedCategory::with('parent')->find($child->id);
        $this->assertNotNull($loaded);
        $this->assertTrue($loaded->relationLoaded('parent'));

        DB::enableQueryLog();
        $loaded->name = 'Renamed';
        $loaded->save();

        $queries = DB::getQueryLog();
        $parentLookups = array_filter(
            $queries,
            static fn (array $q): bool => str_contains((string) $q['query'], 'select "url_path" from "slugged_categories" where "id"'),
        );
        $this->assertSame([], $parentLookups, 'no parent SELECT when relation is loaded');

        $loaded->refresh();
        $this->assertSame('/root/renamed/', $loaded->url_path);
    }
}
