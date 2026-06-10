<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Vusys\NestedSet\Aggregates\AggregateFixResult;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * Reuses the `categories` table but forces `fixAggregates()` to throw, so
 * tests can exercise the deferred-maintenance runner's behaviour when the
 * trailing repair pass fails (it must rethrow on the success path rather
 * than silently swallow the drift).
 *
 * @property int $id
 * @property string $name
 */
final class DeferredRepairBoom extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    protected $table = 'categories';

    /** @var list<string> */
    protected $fillable = ['name'];

    /** @var array<string, string> */
    protected $casts = [
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
    ];

    public static function fixAggregates(
        ?HasNestedSet $anchor = null,
        ?int $chunkSize = null,
        ?Closure $onChunk = null,
    ): AggregateFixResult {
        throw new RuntimeException('repair boom');
    }
}
