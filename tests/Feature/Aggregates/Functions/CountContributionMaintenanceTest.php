<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Functions;

use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\CountArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Pins the per-row contribution of both Count delta paths on update:
 *
 *  - SQL filtered COUNT(`tickets`) — the Identity-transform branch where
 *    a row contributes 1 only when it passes the filter AND its source is
 *    non-null, else 0.
 *  - Listener COUNT — where a row contributes when contribution() is
 *    non-null, else not at all.
 *
 * Each test drives a counted ↔ uncounted transition and asserts the exact
 * rolled-up value on the ancestor plus full stored-vs-recomputed agreement,
 * so a wrong contribution (off-by-one, or an AND relaxed to OR) drifts the
 * stored count and fails.
 */
final class CountContributionMaintenanceTest extends TestCase
{
    use InteractsWithTrees;

    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    private function asInt(mixed $value): int
    {
        $this->assertIsNumeric($value);

        return (int) $value;
    }

    /**
     * root (fire, tickets=10)
     *   └── child (passed-in attributes)
     *
     * @param  array<string, mixed>  $childAttributes
     * @return array{root: CountArea, child: CountArea}
     */
    private function seedCountTree(array $childAttributes): array
    {
        $root = new CountArea(['name' => 'root', 'type' => 'fire', 'tickets' => 10]);
        $root->saveAsRoot();

        $child = new CountArea(['name' => 'child', ...$childAttributes]);
        $child->appendToNode($root->refresh())->save();

        return ['root' => $root->refresh(), 'child' => $child->refresh()];
    }

    public function test_sql_and_listener_count_increment_when_a_child_enters_the_fire_filter(): void
    {
        // child starts as water with tickets — counted by neither.
        ['root' => $root, 'child' => $child] = $this->seedCountTree(['type' => 'water', 'tickets' => 3]);

        $this->assertSame(1, $this->asInt($root->fire_ticket_count));
        $this->assertSame(1, $this->asInt($root->fire_node_count));

        $child->update(['type' => 'fire']);

        $root->refresh();
        $this->assertSame(2, $this->asInt($root->fire_ticket_count));
        $this->assertSame(2, $this->asInt($root->fire_node_count));
        $this->assertAggregatesAreIntact(CountArea::class);
    }

    public function test_sql_and_listener_count_decrement_when_a_child_leaves_the_fire_filter(): void
    {
        ['root' => $root, 'child' => $child] = $this->seedCountTree(['type' => 'fire', 'tickets' => 3]);

        $this->assertSame(2, $this->asInt($root->fire_ticket_count));
        $this->assertSame(2, $this->asInt($root->fire_node_count));

        $child->update(['type' => 'water']);

        $root->refresh();
        $this->assertSame(1, $this->asInt($root->fire_ticket_count));
        $this->assertSame(1, $this->asInt($root->fire_node_count));
        $this->assertAggregatesAreIntact(CountArea::class);
    }

    public function test_sql_count_drops_a_fire_row_whose_source_becomes_null(): void
    {
        // Same filter membership before/after (stays fire) — only the
        // source goes null. COUNT(tickets) must stop counting the row;
        // relaxing the `passes-filter AND source-non-null` conjunction to
        // OR would keep it counted. The listener count keys on type only,
        // so it stays at 2.
        ['root' => $root, 'child' => $child] = $this->seedCountTree(['type' => 'fire', 'tickets' => 3]);

        $this->assertSame(2, $this->asInt($root->fire_ticket_count));

        $child->update(['tickets' => null]);

        $root->refresh();
        $this->assertSame(1, $this->asInt($root->fire_ticket_count));
        $this->assertSame(2, $this->asInt($root->fire_node_count));
        $this->assertAggregatesAreIntact(CountArea::class);
    }

    public function test_sql_count_picks_up_a_fire_row_whose_source_becomes_non_null(): void
    {
        // Mirror of the above on the old-contribution side: the row was
        // fire-but-null (uncounted), and gains a source value.
        ['root' => $root, 'child' => $child] = $this->seedCountTree(['type' => 'fire', 'tickets' => null]);

        $this->assertSame(1, $this->asInt($root->fire_ticket_count));

        $child->update(['tickets' => 5]);

        $root->refresh();
        $this->assertSame(2, $this->asInt($root->fire_ticket_count));
        $this->assertAggregatesAreIntact(CountArea::class);
    }
}
