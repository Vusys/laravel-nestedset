<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\PendingOperation;
use Vusys\NestedSet\Position;

final class PendingOperationTest extends TestCase
{
    public function test_stores_action_node_and_position(): void
    {
        $node = $this->createStub(HasNestedSet::class);
        $op = new PendingOperation(action: 'appendTo', node: $node, position: Position::After);

        $this->assertSame('appendTo', $op->action);
        $this->assertSame($node, $op->node);
        $this->assertSame(Position::After, $op->position);
    }

    public function test_node_defaults_to_null(): void
    {
        $op = new PendingOperation(action: 'makeRoot');

        $this->assertNull($op->node);
    }

    public function test_position_defaults_to_after(): void
    {
        $op = new PendingOperation(action: 'makeRoot');

        $this->assertSame(Position::After, $op->position);
    }

    public function test_before_position_is_stored(): void
    {
        $node = $this->createStub(HasNestedSet::class);
        $op = new PendingOperation(action: 'insertBefore', node: $node, position: Position::Before);

        $this->assertSame(Position::Before, $op->position);
    }
}
