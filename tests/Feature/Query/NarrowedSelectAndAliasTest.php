<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Query;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * withDepth()/withFreshAggregates() must not re-widen a narrowed select(),
 * and ad-hoc fresh-aggregate aliases (interpolated raw into the SELECT)
 * must be validated as bare SQL identifiers.
 */
final class NarrowedSelectAndAliasTest extends TestCase
{
    #[Test]
    public function with_depth_keeps_a_narrowed_select(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->makeRoot()->save();

        $row = Category::query()
            ->select(['id', 'name'])
            ->withDepth()
            ->whereKey($root->id)
            ->firstOrFail();

        $attrs = $row->getAttributes();
        $this->assertArrayHasKey('name', $attrs);
        $this->assertArrayHasKey('depth', $attrs);
        $this->assertArrayNotHasKey('lft', $attrs, 'narrowed select must not be re-widened to *');
    }

    #[Test]
    public function with_fresh_aggregates_keeps_a_narrowed_select(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 3]);
        $root->makeRoot()->save();

        $row = Area::query()
            ->select(['id', 'name'])
            ->withFreshAggregates(['fresh_total' => Aggregate::sum('tickets')])
            ->whereKey($root->id)
            ->firstOrFail();

        $attrs = $row->getAttributes();
        $this->assertArrayHasKey('fresh_total', $attrs);
        $this->assertArrayNotHasKey('lft', $attrs, 'narrowed select must not be re-widened to *');
    }

    #[Test]
    public function fresh_aggregate_alias_must_be_a_bare_identifier(): void
    {
        $this->expectException(AggregateConfigurationException::class);

        Area::query()->withFreshAggregates([
            'x, (SELECT name FROM areas LIMIT 1) AS smuggled' => Aggregate::sum('tickets'),
        ])->get();
    }
}
