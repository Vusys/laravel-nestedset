<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\TestCase;

/**
 * A source-column update issued through a stale in-memory instance must
 * band its ancestor delta against the row's *current* DB bounds, not the
 * bounds it was loaded with. Another mutation may have shifted the row
 * since; using the stale band hits the wrong ancestors and drifts
 * permanently. The delete/move/restore paths already re-read bounds;
 * this pins the source-update path.
 */
final class StaleInstanceDeltaTest extends TestCase
{
    use InteractsWithTrees;

    #[Test]
    public function source_update_through_a_stale_instance_does_not_drift_aggregates(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 0]);
        $root->makeRoot()->save();

        $a = new Area(['name' => 'A', 'tickets' => 0]);
        $a->appendToNode($root->refresh())->save();

        $x = new Area(['name' => 'X', 'tickets' => 5]);
        $x->appendToNode($a->refresh())->save();

        // Capture a stale handle on X, then shift its bounds by prepending
        // a sibling subtree under the root — X's lft/rgt move right by 2.
        $staleX = Area::query()->findOrFail($x->id);

        $shift = new Area(['name' => 'Shift', 'tickets' => 0]);
        $shift->prependToNode($root->refresh())->save();

        // The stale instance still holds the pre-shift bounds. Updating its
        // source column must re-read bounds before banding the delta.
        $staleX->tickets = 100;
        $staleX->save();

        $this->assertAggregatesAreIntact(Area::class);
    }
}
