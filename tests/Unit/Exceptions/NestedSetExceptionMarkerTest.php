<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;
use Vusys\NestedSet\Exceptions\NestedSetException;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\Exceptions\UnplacedNodeException;

/**
 * Every concrete exception the package defines must implement the
 * NestedSetException marker so callers can catch any package-originated
 * failure with a single catch block (docs/reference/factories.md).
 */
final class NestedSetExceptionMarkerTest extends TestCase
{
    #[Test]
    public function the_marker_is_a_throwable_interface(): void
    {
        $ref = new ReflectionClass(NestedSetException::class);
        $this->assertTrue($ref->isInterface());
        $this->assertContains(Throwable::class, $ref->getInterfaceNames());
    }

    #[Test]
    public function every_concrete_exception_implements_the_marker(): void
    {
        $dir = __DIR__.'/../../../src/Exceptions';
        $missing = [];

        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $class = 'Vusys\\NestedSet\\Exceptions\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue; // the marker interface itself, or any non-class
            }

            $ref = new ReflectionClass($class);
            if ($ref->isAbstract()) {
                continue;
            }

            if (! $ref->implementsInterface(NestedSetException::class)) {
                $missing[] = $class;
            }
        }

        $this->assertSame([], $missing, 'these exceptions do not implement NestedSetException: '.implode(', ', $missing));
    }

    #[Test]
    public function a_thrown_exception_is_catchable_via_the_marker(): void
    {
        try {
            throw new UnplacedNodeException('unplaced');
        } catch (NestedSetException $e) {
            $this->assertInstanceOf(UnplacedNodeException::class, $e);
        }

        try {
            throw new ScopeViolationException('scope');
        } catch (NestedSetException $e) {
            $this->assertInstanceOf(ScopeViolationException::class, $e);
        }
    }
}
