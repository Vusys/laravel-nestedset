<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * `$guarded` is non-empty but does NOT cover the aggregate column.
 * The registry must reject this configuration just like the
 * unguarded case — a partial guard list that omits the aggregate
 * still permits mass-assignment of it.
 */
#[NestedSetAggregate(column: 'tickets_total', sum: 'tickets')]
final class PartiallyGuardedArea extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    protected $table = 'areas';

    /** @var list<string> */
    protected $guarded = ['some_other_column'];
}
