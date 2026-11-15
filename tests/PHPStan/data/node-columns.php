<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\PHPStan\Data;

use Illuminate\Database\Eloquent\Model;

use function PHPStan\Testing\assertType;

use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/** A node with no @property docblock: the extension alone says what these are. */
class BareNode extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;
}

class BareChildNode extends BareNode {}

function node(BareNode $node, BareChildNode $child): void
{
    assertType('int', $node->lft);
    assertType('int', $node->rgt);
    assertType('int', $node->depth);
    assertType('int|null', $node->parent_id);
    assertType('int|null', $child->parent_id);
}
