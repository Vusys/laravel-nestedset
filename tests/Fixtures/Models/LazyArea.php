<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Attributes\NestedSetAggregateListener;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\DoubleTicketsListener;

/**
 * Fixture model covering the lazy-aggregate lifecycle. Carries one
 * non-lazy eager Sum (`tickets_total`, the control), and a matrix of
 * lazy declarations exercising SQL inclusive / SQL exclusive / SQL Count,
 * a TTL'd column, and a listener variant.
 *
 * Source column: `tickets`. The listener doubles the contribution so
 * `lazy_listener_sum` reads cleanly as 2× the SQL sum for the same
 * subtree — handy for distinguishing the two paths in assertions.
 *
 * @property int $id
 * @property string $name
 * @property int $tickets
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property int $tickets_total eager control
 * @property int|null $lazy_tickets_total lazy Sum (no TTL)
 * @property string|null $lazy_tickets_total_computed_at
 * @property int|null $lazy_tickets_count lazy Count(*)
 * @property string|null $lazy_tickets_count_computed_at
 * @property int|null $lazy_tickets_total_ttl lazy Sum with TTL
 * @property string|null $lazy_tickets_total_ttl_computed_at
 * @property int|null $lazy_descendants_total lazy exclusive Sum
 * @property string|null $lazy_descendants_total_computed_at
 * @property int|null $lazy_listener_sum lazy listener Sum
 * @property string|null $lazy_listener_sum_computed_at
 */
#[NestedSetAggregate(column: 'tickets_total', sum: 'tickets')]
#[NestedSetAggregate(column: 'lazy_tickets_total', sum: 'tickets', lazy: true)]
#[NestedSetAggregate(column: 'lazy_tickets_count', count: true, lazy: true)]
#[NestedSetAggregate(column: 'lazy_tickets_total_ttl', sum: 'tickets', lazy: true, ttl: 60)]
#[NestedSetAggregate(column: 'lazy_descendants_total', sum: 'tickets', exclusive: true, lazy: true)]
#[NestedSetAggregateListener(
    column: 'lazy_listener_sum',
    listener: DoubleTicketsListener::class,
    operation: AggregateFunction::Sum,
    lazy: true,
)]
final class LazyArea extends Model implements HasNestedSet
{
    use NodeTrait;

    protected $table = 'lazy_areas';

    /** @var list<string> */
    protected $fillable = ['name', 'tickets'];

    /** @var array<string, string> */
    protected $casts = [
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
        'tickets' => 'integer',
        'tickets_total' => 'integer',
        'lazy_tickets_total' => 'integer',
        'lazy_tickets_count' => 'integer',
        'lazy_tickets_total_ttl' => 'integer',
        'lazy_descendants_total' => 'integer',
        'lazy_listener_sum' => 'integer',
    ];
}
