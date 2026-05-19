<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

/**
 * Demonstrates the recommended escape hatch for projects that
 * generally use `$guarded = []`: list the aggregate columns in
 * `$guarded` explicitly. The registry should accept this — those
 * columns are now protected from mass-assignment.
 */
#[NestedSetAggregate(column: 'tickets_total', sum: 'tickets')]
final class AggregateColumnGuardedArea extends Model implements HasNestedSet
{
    use NodeTrait;

    protected $table = 'areas';

    /** @var list<string> */
    protected $guarded = ['tickets_total'];
}
