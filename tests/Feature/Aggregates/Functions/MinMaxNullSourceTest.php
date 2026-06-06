<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Functions;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\NullableMetricArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Regression coverage for issue #178 — `CreateHookApplier` and
 * `RestoreHookApplier` formerly read the MIN/MAX source via
 * `Numeric::asNumericOrZero(...)`, which collapsed a NULL value to 0
 * and injected it as a candidate extremum on the ancestor chain. SQL
 * MIN/MAX ignore NULL; the lifecycle paths now mirror that rule.
 */
final class MinMaxNullSourceTest extends TestCase
{
    use InteractsWithTrees;

    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    #[Test]
    public function create_with_null_source_does_not_lower_ancestor_max(): void
    {
        $root = new NullableMetricArea(['name' => 'Root', 'score' => 100]);
        $root->saveAsRoot();
        $root->refresh();

        $this->assertSame(100, (int) $root->score_max);
        $this->assertSame(100, (int) $root->score_min);

        // Insert a child with NULL score — SQL MIN/MAX over the
        // subtree would still report 100/100; the lifecycle hook
        // must agree, not push a 0 candidate that lowers the MAX.
        $child = new NullableMetricArea(['name' => 'Null child', 'score' => null]);
        $child->appendToNode($root->refresh())->save();

        $root->refresh();
        $this->assertSame(100, (int) $root->score_max);
        $this->assertSame(100, (int) $root->score_min);

        $this->assertAggregateMatchesFresh($root, 'score_max');
        $this->assertAggregateMatchesFresh($root, 'score_min');
    }

    #[Test]
    public function create_with_null_source_at_root_keeps_extrema_null(): void
    {
        // Root with NULL score: its own MIN/MAX should stay NULL,
        // not be stamped to 0 by an asNumericOrZero coercion.
        $root = new NullableMetricArea(['name' => 'Root', 'score' => null]);
        $root->saveAsRoot();
        $root->refresh();

        $this->assertNull($root->score_max);
        $this->assertNull($root->score_min);

        $this->assertAggregateMatchesFresh($root, 'score_max');
        $this->assertAggregateMatchesFresh($root, 'score_min');
    }

    #[Test]
    public function hard_restore_with_null_stored_extremum_does_not_clobber_ancestor(): void
    {
        // The non-soft-delete branch of RestoreHookApplier reads the
        // stored aggregate column on the restored node and would push
        // 0 to ancestors if that column was NULL. Direct invocation of
        // the public restore hook is the only way to reach that branch
        // — the Laravel `restored` model event only fires for
        // SoftDeletes models, which take the soft-delete branch.
        $root = new NullableMetricArea(['name' => 'Root', 'score' => 100]);
        $root->saveAsRoot();

        $child = new NullableMetricArea(['name' => 'Child', 'score' => 50]);
        $child->appendToNode($root->refresh())->save();

        // Force the restored node's stored extremum to NULL — the
        // scenario the hard-restore path needs to handle.
        DB::table('nullable_metric_areas')
            ->where('id', $child->getKey())
            ->update(['score_max' => null, 'score_min' => null]);
        $child->refresh();

        $root->refresh();
        $this->assertSame(100, (int) $root->score_max);
        $this->assertSame(50, (int) $root->score_min);

        $child->applyAggregateOnRestore();

        $root->refresh();
        // Pre-fix: ancestor MIN would collapse to 0 — the NULL stored
        // extremum on $child got coerced into a 0 candidate and
        // MIN(stored=50, 0) = 0. Post-fix: no candidate is pushed,
        // root's stored MIN stays 50. MAX is unaffected either way
        // because MAX(stored=100, 0) = 100.
        $this->assertSame(100, (int) $root->score_max);
        $this->assertSame(50, (int) $root->score_min);
    }
}
