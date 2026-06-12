<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Integrity;

use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\TestCase;

/**
 * A fresh-aggregate read combined with `orderBy()` + `limit()`/`offset()`.
 * On MariaDB the derived-table path embeds the user's query as a
 * set-membership subquery (`o.id IN (…)`); carrying the user's `LIMIT`
 * there is rejected with error 1235 ("LIMIT & IN/ALL/ANY/SOME subquery").
 * The fix strips order/limit/offset from that id projection (they belong
 * to the outer query, which still bounds the final result). This exercises
 * it on every backend.
 */
final class FreshReadWithLimitTest extends TestCase
{
    private function seedTree(): void
    {
        $root = new Area(['name' => 'root', 'tickets' => 0]);
        $root->saveAsRoot();

        // Five leaves with known per-row inclusive sums (a leaf's fresh
        // sum is its own tickets).
        foreach (['a' => 10, 'b' => 20, 'c' => 30, 'd' => 40, 'e' => 50] as $name => $tickets) {
            (new Area(['name' => $name, 'tickets' => $tickets]))
                ->appendToNode($root->refresh())
                ->save();
        }
    }

    #[Test]
    public function fresh_read_with_order_and_limit_returns_the_limited_rows(): void
    {
        $this->seedTree();

        $rows = Area::query()
            ->whereIsLeaf()
            ->orderBy('name')
            ->limit(2)
            ->withFreshAggregates(['fresh_tickets' => Aggregate::sum('tickets')])
            ->get();

        // Outer order + limit still bound the result: the first two leaves
        // by name are a(10) and b(20).
        $this->assertSame(['a', 'b'], $rows->pluck('name')->all());
        $this->assertFreshTickets($rows, [10, 20]);
    }

    #[Test]
    public function fresh_read_with_offset_returns_correct_window(): void
    {
        $this->seedTree();

        $rows = Area::query()
            ->whereIsLeaf()
            ->orderBy('name')
            ->offset(3)
            ->limit(2)
            ->withFreshAggregates(['fresh_tickets' => Aggregate::sum('tickets')])
            ->get();

        $this->assertSame(['d', 'e'], $rows->pluck('name')->all());
        $this->assertFreshTickets($rows, [40, 50]);
    }

    /**
     * @param  Collection<int, Area>  $rows
     * @param  list<int>  $expected
     */
    private function assertFreshTickets(Collection $rows, array $expected): void
    {
        foreach ($expected as $i => $value) {
            $row = $rows->get($i);
            $this->assertInstanceOf(Area::class, $row);
            $this->assertEquals($value, $row->getAttribute('fresh_tickets'));
        }
    }
}
