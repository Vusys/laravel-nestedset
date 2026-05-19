<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Attributes\NestedSetAggregateListener;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\UuidNameLengthListener;

/**
 * Custom-primary-key fixture with a string (UUID) PK. Every package
 * path that handles ids must accept the string identifier rather than
 * narrowing to int — the trait, repair builder, aggregate cursor, and
 * queued job all flow this id through.
 *
 * Laravel's {@see HasUuids} trait generates ordered UUIDv7 ids; that's
 * the supported shape for chunked aggregate repair (lexicographic
 * cursor ordering matches insert order).
 *
 * @property string $id
 * @property string $name
 * @property int $tickets
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property string|null $parent_id
 * @property int $tickets_total
 * @property int $name_length_total
 */
#[NestedSetAggregate(column: 'tickets_total', sum: 'tickets')]
#[NestedSetAggregateListener(column: 'name_length_total', listener: UuidNameLengthListener::class, operation: AggregateFunction::Sum)]
final class UuidTag extends Model implements HasNestedSet
{
    use HasUuids;
    use NodeTrait;

    protected $table = 'uuid_tags';

    /** @var list<string> */
    protected $fillable = ['name', 'tickets'];

    /** @var array<string, string> */
    protected $casts = [
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'tickets' => 'integer',
        'tickets_total' => 'integer',
        'name_length_total' => 'integer',
    ];
}
