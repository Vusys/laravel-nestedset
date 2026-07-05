<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\NodeTrait;

/**
 * Deliberately misconfigured fixture: composes {@see NodeTrait} but omits
 * `implements MaintainsTreeAggregates`. Exists solely so
 * MissingContractGuardTest can prove the `saving` listener throws
 * MisconfiguredNodeException instead of silently inserting a row with
 * lft = rgt = 0.
 *
 * Reuses the `categories` table so no migration is needed. This file is
 * excluded from PHPStan analysis (see phpstan.neon) because the trait's
 * `@phpstan-require-implements MaintainsTreeAggregates` constraint — which
 * catches this exact mistake statically — would otherwise (correctly) flag
 * it. The whole point of the fixture is to be misconfigured.
 */
final class BareNodeWithoutContract extends Model
{
    use NodeTrait;

    protected $table = 'categories';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];
}
