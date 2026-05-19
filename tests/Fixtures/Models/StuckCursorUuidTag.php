<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\AggregateFixResult;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

/**
 * Sibling of {@see UuidTag} that shares the same `uuid_tags` table but
 * overrides {@see self::fixAggregatesChunk()} to always return the
 * same cursor — simulates a buggy backend or corrupted index where
 * the chunk loop's `nextAfterId` never advances.
 *
 * Lets the test suite pin the non-progress check in
 * `fixAggregatesChunked` without removing `final` from `UuidTag`
 * (which would trip PHPStan's `new static()` variance rule on every
 * trait path that constructs a fresh instance).
 */
#[NestedSetAggregate(column: 'tickets_total', sum: 'tickets')]
final class StuckCursorUuidTag extends Model implements HasNestedSet
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
    ];

    /**
     * @return array{result: AggregateFixResult, nextAfterId: int|string|null}
     */
    public static function fixAggregatesChunk(
        ?HasNestedSet $anchor,
        int|string|null $afterId,
        int $chunkSize,
    ): array {
        return [
            'result' => new AggregateFixResult(totalRowsUpdated: 0, perColumn: []),
            'nextAfterId' => 'stuck-cursor-sentinel',
        ];
    }
}
