<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

/**
 * Intentionally declares `protected $guarded = []` (the modern
 * Laravel idiom that allows every column to be mass-assigned) with
 * an aggregate column also declared. The registry must reject this
 * configuration at boot — without the guard, mass-assigning the
 * aggregate column would be silently overwritten on the next
 * mutation.
 */
#[NestedSetAggregate(column: 'tickets_total', sum: 'tickets')]
final class UnguardedArea extends Model implements HasNestedSet
{
    use NodeTrait;

    protected $table = 'areas';

    /** @var list<string> */
    protected $guarded = [];
}
