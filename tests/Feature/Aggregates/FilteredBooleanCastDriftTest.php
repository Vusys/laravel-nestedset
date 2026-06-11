<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\FlagArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * A SUM filtered by an equality predicate on a boolean-cast column
 * (`filter: ['active' => true]`, `active` cast to bool over a TINYINT)
 * used to drift: during delta capture the new predicate side was
 * evaluated against RAW attributes (active = 1) while the old side used
 * cast attributes (active = true). Under the predicate's strict
 * comparison the two disagreed, so an ordinary value change on an
 * active row was mis-captured as the row leaving the filter — a phantom
 * subtraction the SQL side never made. Both sides now evaluate through
 * the model's cast.
 */
final class FilteredBooleanCastDriftTest extends TestCase
{
    use InteractsWithTrees;

    /**
     * @return array{root: FlagArea, child: FlagArea}
     */
    private function seedTree(): array
    {
        $root = new FlagArea(['name' => 'root', 'active' => true, 'value' => 0]);
        $root->saveAsRoot();

        $child = new FlagArea(['name' => 'child', 'active' => true, 'value' => 10]);
        $child->appendToNode($root->refresh())->save();

        return ['root' => $root->refresh(), 'child' => $child->refresh()];
    }

    #[Test]
    public function changing_value_on_an_active_row_does_not_drift_the_filtered_sum(): void
    {
        $tree = $this->seedTree();
        $this->assertSame(10, (int) $tree['root']->active_value_total);

        // Plain value change while active stays true. The filter
        // predicate must read the same (cast) `active` on both sides, or
        // it mis-fires and the ancestor rollup drifts.
        $child = $tree['child'];
        $child->value = 50;
        $child->save();

        $this->assertAggregatesAreIntact(FlagArea::class);
        $this->assertSame(50, (int) $tree['root']->refresh()->active_value_total);
    }

    #[Test]
    public function toggling_active_moves_the_contribution_in_and_out_of_the_filter(): void
    {
        $tree = $this->seedTree();
        $child = $tree['child'];

        // active true -> false: the child leaves the filter, its 10
        // must be removed from the rollup.
        $child->active = false;
        $child->save();
        $this->assertAggregatesAreIntact(FlagArea::class);
        $this->assertSame(0, (int) $tree['root']->refresh()->active_value_total);

        // active false -> true: it re-enters, contributing 10 again.
        $child->active = true;
        $child->save();
        $this->assertAggregatesAreIntact(FlagArea::class);
        $this->assertSame(10, (int) $tree['root']->refresh()->active_value_total);
    }
}
