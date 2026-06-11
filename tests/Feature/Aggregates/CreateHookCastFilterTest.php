<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\BoolFilterArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * The create-path aggregate hook must evaluate Equality filters against
 * cast attribute values, not the raw set. `active = 1` (int) created on a
 * boolean-cast column has to satisfy `filter: ['active' => true]` exactly
 * as the SQL filter sees it — a raw read excludes the row and drifts.
 */
final class CreateHookCastFilterTest extends TestCase
{
    use InteractsWithTrees;

    #[Test]
    public function create_with_int_one_satisfies_a_boolean_true_filter(): void
    {
        $root = new BoolFilterArea(['name' => 'Root', 'tickets' => 0, 'active' => true]);
        $root->makeRoot()->save();

        // Set the boolean column with a raw int 1 — the footgun the SQL
        // filter (active = 1) handles but a raw PHP read does not.
        $child = new BoolFilterArea(['name' => 'Child', 'tickets' => 10]);
        $child->setAttribute('active', 1);
        $child->appendToNode($root->refresh())->save();

        $this->assertAggregatesAreIntact(BoolFilterArea::class);
        // Both root and child are active; child contributes its 10 tickets.
        $this->assertSame(10, $root->refresh()->active_tickets);
        $this->assertSame(2, $root->refresh()->active_count);
    }
}
