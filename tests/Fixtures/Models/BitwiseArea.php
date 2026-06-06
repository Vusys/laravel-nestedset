<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * Fixture exercising the three bitwise rollups added in M2 — bitOr,
 * bitAnd, and bitXor. Each operates on the same `feature_bits` source
 * column so a single tree shape covers all three folds.
 *
 * bitOr: "does any descendant have feature X?" — useful for feature
 *        flag rollup.
 * bitAnd: "do all descendants have feature X?" — useful for capability
 *        intersection.
 * bitXor: subtree fingerprint — useful as a cheap order-independent
 *        checksum.
 *
 * @property int $id
 * @property string $name
 * @property int $feature_bits
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property int|null $features_or
 * @property int|null $features_and
 * @property int|null $features_xor
 * @property-read Collection<int, BitwiseArea> $children
 * @property-read BitwiseArea|null $parent
 */
#[NestedSetAggregate(column: 'features_or', bitOr: 'feature_bits')]
#[NestedSetAggregate(column: 'features_and', bitAnd: 'feature_bits')]
#[NestedSetAggregate(column: 'features_xor', bitXor: 'feature_bits')]
final class BitwiseArea extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    protected $table = 'bitwise_areas';

    /** @var list<string> */
    protected $fillable = ['name', 'feature_bits'];

    /** @var array<string, string> */
    protected $casts = [
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
        'feature_bits' => 'integer',
        'features_or' => 'integer',
        'features_and' => 'integer',
        'features_xor' => 'integer',
    ];
}
