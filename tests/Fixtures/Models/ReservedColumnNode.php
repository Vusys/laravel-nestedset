<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * Every structural column is a genuine SQL reserved word — `left`, `right`,
 * `order`. Unlike {@see CustomColumnsBranch} (which renames to safe
 * identifiers like `tree_lft`), this fixture forces every raw-SQL path that
 * interpolates a structural column name to grammar-quote it, or the
 * mutation/repair UPDATE is a backend-specific syntax error. No aggregates
 * — this isolates the structural CASE-WHEN write path and fixTree.
 *
 * @property int $id
 * @property string $name
 * @property int $left
 * @property int $right
 * @property int $order
 * @property int|null $parent
 */
final class ReservedColumnNode extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    protected $table = 'reserved_column_nodes';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['name'];

    /** @var array<string, string> */
    protected $casts = [
        'left' => 'integer',
        'right' => 'integer',
        'order' => 'integer',
        'parent' => 'integer',
    ];

    public function getLftName(): string
    {
        return 'left';
    }

    public function getRgtName(): string
    {
        return 'right';
    }

    public function getDepthName(): string
    {
        return 'order';
    }

    public function getParentIdName(): string
    {
        return 'parent';
    }
}
