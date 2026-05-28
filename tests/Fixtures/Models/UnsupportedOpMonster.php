<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Attributes\NestedSetAggregateListener;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\FireCountListener;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\WeightedPowerListener;

/**
 * Misconfiguration fixture: declares listener aggregates with operations
 * the PHP listener path cannot compute (Variance is rejected with a
 * LogicException; Median with an AggregateConfigurationException). The
 * definitions are constructible — only bitwise ops are refused at
 * construction — so the guard fires lazily when a fresh read is taken.
 *
 * Reuses the `monsters` table and the existing listener classes; it is
 * never saved through Eloquent (that would trip the same guard during
 * maintenance), only read against a pre-seeded row.
 */
#[NestedSetAggregateListener(column: 'weighted_power', listener: WeightedPowerListener::class, operation: AggregateFunction::Variance)]
#[NestedSetAggregateListener(column: 'fire_count', listener: FireCountListener::class, operation: AggregateFunction::Median)]
final class UnsupportedOpMonster extends Model implements HasNestedSet
{
    use NodeTrait;

    protected $table = 'monsters';

    /** @var list<string> */
    protected $fillable = ['name', 'type', 'base_power', 'level'];

    /** @var array<string, string> */
    protected $casts = [
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
        'base_power' => 'integer',
        'level' => 'integer',
    ];
}
