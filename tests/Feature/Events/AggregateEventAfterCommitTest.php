<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Events;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Events\Aggregates\NestedSetAggregateChanged;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\TestCase;

/**
 * The change-feed event (ShouldDispatchAfterCommit) must not reach a real
 * listener when its enclosing transaction rolls back — otherwise a
 * downstream mirror (search index, cache, CDC stream) observes a phantom
 * change. A committed mutation must still deliver it.
 */
final class AggregateEventAfterCommitTest extends TestCase
{
    #[Test]
    public function change_feed_event_is_dropped_on_rollback_and_delivered_on_commit(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 0]);
        $root->makeRoot()->save();
        $child = new Area(['name' => 'Child', 'tickets' => 5]);
        $child->appendToNode($root->refresh())->save();

        $observed = 0;
        Event::listen(NestedSetAggregateChanged::class, function () use (&$observed): void {
            $observed++;
        });

        // Rolled-back mutation: the change-feed event must be dropped.
        try {
            DB::transaction(function () use ($child): never {
                $child->tickets = 100;
                $child->save();

                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, $observed, 'a rolled-back change must not reach the change-feed listener');

        // Committed mutation: the event is delivered after commit.
        $child->refresh();
        $child->tickets = 50;
        $child->save();

        $this->assertGreaterThan(0, $observed, 'a committed change must reach the change-feed listener');
    }
}
