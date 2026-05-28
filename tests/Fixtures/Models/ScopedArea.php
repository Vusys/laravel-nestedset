<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

/**
 * Scoped + maintained-aggregate fixture. Declares its scope via the
 * method form (`getScopeAttributes()`) rather than the `#[NestedSetScope]`
 * attribute, and maintains a SUM (delta path) and a MIN (recompute path)
 * aggregate. The attribute-form scoped fixtures (MenuItem) carry no
 * aggregates, so this is the only fixture combining partitioned trees
 * with aggregate maintenance — it exercises the scope predicates in
 * both maintenance strategies and the method-form branch of the scope
 * resolver.
 *
 * @property int $id
 * @property string $name
 * @property int $tenant_id
 * @property int $amount
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property int $amount_total
 * @property int|null $amount_min
 */
#[NestedSetAggregate(column: 'amount_total', sum: 'amount')]
#[NestedSetAggregate(column: 'amount_min', min: 'amount')]
final class ScopedArea extends Model implements HasNestedSet
{
    use NodeTrait;

    /** @var list<string> */
    protected $fillable = ['name', 'tenant_id', 'amount'];

    /**
     * `tenant_id` is intentionally left uncast: the scope resolver is
     * type-permissive (it compares numeric strings, DateTimes, and
     * Stringables, not just ints) precisely so mixed-type scope values
     * survive. Casting here would mask that path.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'integer',
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
        'amount_total' => 'integer',
        'amount_min' => 'integer',
    ];

    /**
     * Method-form scope declaration (the alternative to `#[NestedSetScope]`).
     *
     * @return list<string>
     */
    public function getScopeAttributes(): array
    {
        return ['tenant_id'];
    }
}
