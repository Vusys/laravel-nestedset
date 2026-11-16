<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\PHPStan;

use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The shipped PHPStan extension types the nestedSet() columns on any NodeTrait
 * model, including subclasses, without @property docblocks (#261).
 */
final class NodeColumnsExtensionTest extends TypeInferenceTestCase
{
    /** @return iterable<mixed> */
    public static function dataFileAsserts(): iterable
    {
        // Loaded so its classes exist for PHPStan's reflection.
        require_once __DIR__.'/data/node-columns.php';

        yield from self::gatherAssertTypes(__DIR__.'/data/node-columns.php');
    }

    #[DataProvider('dataFileAsserts')]
    public function test_file_asserts(string $assertType, string $file, mixed ...$args): void
    {
        $this->assertFileAsserts($assertType, $file, ...$args);
    }

    /** @return list<string> */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__.'/extension-test.neon'];
    }
}
