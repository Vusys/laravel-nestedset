<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Attributes\NestedSetScope;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

/**
 * Fixture with TWO scope columns. Every other scoped fixture
 * (MenuItem, UuidMenuItem, ScopedArea) declares exactly one — so the
 * `foreach ($scope as $col => $value)` loops that build SQL fragments
 * in AggregateDiffer::isChainShape() only iterated once across the
 * entire suite, leaving `.=` accumulations indistinguishable from a
 * single assignment. This fixture turns those loops over two columns
 * so a `.= → =` mutation drops both the SELECT prefix and the first
 * scope predicate — yielding malformed SQL that the test catches.
 *
 * Plain SUM + COUNT only (delta-maintainable, unfiltered) so the
 * chain-fold fast-path in AggregateDiffer is eligible — it's gated on
 * `! $anyFiltered && ! $anyRecomputeOnly`. Filtered or recompute-only
 * aggregates would short-circuit before isChainShape() is called.
 *
 * @property int $id
 * @property string $name
 * @property int $tenant_id
 * @property int $site_id
 * @property int $tickets
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property int $tickets_total
 * @property int $tickets_count
 */
#[NestedSetScope(['tenant_id', 'site_id'])]
#[NestedSetAggregate(column: 'tickets_total', sum: 'tickets')]
#[NestedSetAggregate(column: 'tickets_count', count: true)]
final class MultiScopedBranch extends Model implements HasNestedSet
{
    use NodeTrait;

    /** @var list<string> */
    protected $fillable = ['name', 'tenant_id', 'site_id', 'tickets'];

    /** @var array<string, string> */
    protected $casts = [
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'tickets' => 'integer',
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
        'tickets_total' => 'integer',
        'tickets_count' => 'integer',
    ];
}
