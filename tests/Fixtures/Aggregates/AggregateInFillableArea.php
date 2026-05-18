<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

/**
 * Intentionally lists an aggregate column in $fillable to exercise
 * the AggregateRegistry's mass-assignment guard. Real models must
 * never do this — the package overwrites these columns on every
 * mutation, so any mass-assigned value is silently lost.
 */
#[NestedSetAggregate(column: 'tickets_total', sum: 'tickets')]
final class AggregateInFillableArea extends Model implements HasNestedSet
{
    use NodeTrait;

    protected $table = 'areas';

    /** @var list<string> */
    protected $fillable = ['name', 'tickets', 'tickets_total'];
}
