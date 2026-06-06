<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Attributes\NestedSetAggregateListener;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\FireNodeCountListener;

/**
 * Count-contribution fixture. Pairs the two Count delta paths that no
 * other fixture exercises with a contributing/non-contributing toggle:
 *
 *  - `fire_ticket_count` — a method-form SQL COUNT(`tickets`) filtered to
 *    `type = 'fire'`. Unlike the attribute-form `count: true` (= COUNT(*),
 *    whose source is null), the sourced variant runs the Identity-transform
 *    branch where a row contributes only when it passes the filter AND its
 *    source is non-null.
 *  - `fire_node_count` — a listener aggregate with `operation: Count`,
 *    counting only fire-type nodes ({@see FireNodeCountListener} returns
 *    null for the rest).
 *
 * `tickets` is nullable and `type` toggles filter membership, so both
 * paths see real counted ↔ uncounted transitions on update.
 *
 * @property int $id
 * @property string $name
 * @property int|null $tickets
 * @property string|null $type
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property int $fire_ticket_count
 * @property int $fire_node_count
 */
#[NestedSetAggregateListener(column: 'fire_node_count', listener: FireNodeCountListener::class, operation: AggregateFunction::Count)]
final class CountArea extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    protected $table = 'count_areas';

    /** @var list<string> */
    protected $fillable = ['name', 'tickets', 'type'];

    /** @var array<string, string> */
    protected $casts = [
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
        'tickets' => 'integer',
        'fire_ticket_count' => 'integer',
        'fire_node_count' => 'integer',
    ];

    /** @return list<AggregateDefinition> */
    protected function nestedSetAggregates(): array
    {
        return [
            Aggregate::count('tickets')->filter(['type' => 'fire'])->into('fire_ticket_count'),
        ];
    }
}
