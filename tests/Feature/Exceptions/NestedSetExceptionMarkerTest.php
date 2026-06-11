<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Exceptions;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Exceptions\NestedSetException;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\Exceptions\UnplacedNodeException;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Every exception the package throws — the named ones and the internal
 * invariant/validation throws alike — must be trappable via a single
 * `catch (NestedSetException)`, while still extending its SPL base.
 */
final class NestedSetExceptionMarkerTest extends TestCase
{
    #[Test]
    public function named_exceptions_carry_the_marker(): void
    {
        $this->assertInstanceOf(NestedSetException::class, new UnplacedNodeException('x'));
        $this->assertInstanceOf(NestedSetException::class, new ScopeViolationException('x'));
        // …and still extend their SPL base.
        $this->assertInstanceOf(\LogicException::class, new UnplacedNodeException('x'));
    }

    #[Test]
    public function a_bare_throw_site_is_caught_as_a_nested_set_exception(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->makeRoot()->save();

        $node = new Category(['name' => 'X']);
        $node->appendToNode($root->refresh())->save();

        // moveTo() with a negative position is an internal validation throw
        // (formerly a bare LogicException). It must surface as both a
        // NestedSetException and a LogicException.
        try {
            $node->moveTo($root->refresh(), -1);
            $this->fail('expected a validation exception');
        } catch (NestedSetException $e) {
            $this->assertInstanceOf(\LogicException::class, $e);
        }
    }
}
