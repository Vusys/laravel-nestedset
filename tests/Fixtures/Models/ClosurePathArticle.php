<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\MaterialisedPath\MaterialisedPath;
use Vusys\NestedSet\NodeTrait;

/**
 * @property int $id
 * @property string $title
 * @property string|null $breadcrumb_path
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 */
final class ClosurePathArticle extends Model implements HasNestedSet
{
    use NodeTrait;

    /** @var list<string> */
    protected $fillable = ['title'];

    /** @var array<string, string> */
    protected $casts = [
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
    ];

    /**
     * @return array<string, MaterialisedPath|callable|string>
     */
    protected static function materialisedPaths(): array
    {
        return [
            'breadcrumb_path' => MaterialisedPath::from(
                static fn (self $node): string => Str::slug((string) ($node->title ?? ''), '-'),
            )->separator('/')->maxLength(2048),
        ];
    }
}
