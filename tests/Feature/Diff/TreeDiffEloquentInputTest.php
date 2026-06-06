<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Diff;

use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Diff\TreeDiff;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * `between()` accepts iterables of Eloquent models. Each row goes
 * through `Model::attributesToArray()`, so the diff sees cast values
 * (Carbon instances, JSON-decoded arrays, etc.) — not raw DB strings.
 */
final class TreeDiffEloquentInputTest extends TestCase
{
    #[Test]
    public function collection_of_eloquent_models_is_diffed(): void
    {
        $root = new Category(['name' => 'r']);
        $root->makeRoot()->save();
        $child = new Category(['name' => 'c']);
        $child->appendToNode($root)->save();

        $before = Category::query()->orderBy('lft')->get();
        $child->name = 'c-renamed';
        $child->save();
        $after = Category::query()->orderBy('lft')->get();

        $this->assertInstanceOf(Collection::class, $before);
        $diff = TreeDiff::between($before, $after);

        $this->assertCount(1, $diff->modified);
        $this->assertSame($child->id, $diff->modified[0]->key);
    }
}
