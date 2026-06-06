<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Exceptions\JsonImportKeyCollisionException;

final class JsonImportKeyCollisionExceptionTest extends TestCase
{
    #[Test]
    public function offending_key_is_surfaced_on_the_exception(): void
    {
        $previous = new \RuntimeException('underlying unique constraint failed');
        $e = new JsonImportKeyCollisionException(42, 'PK collision on includeKeys=true', $previous);

        $this->assertSame(42, $e->offendingKey);
        $this->assertSame('PK collision on includeKeys=true', $e->getMessage());
        $this->assertSame($previous, $e->getPrevious());
    }
}
