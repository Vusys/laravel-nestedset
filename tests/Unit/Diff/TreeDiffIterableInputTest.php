<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Diff;

use Generator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Diff\TreeDiff;

/**
 * `between()` accepts any iterable — including generators — not just
 * arrays. The internal `materialise()` path is what makes that work.
 */
final class TreeDiffIterableInputTest extends TestCase
{
    #[Test]
    public function generator_input_is_materialised_and_diffed(): void
    {
        $beforeGen = $this->generator([
            ['id' => 1, 'name' => 'r', 'parent_id' => null],
        ]);
        $afterGen = $this->generator([
            ['id' => 1, 'name' => 'r', 'parent_id' => null],
            ['id' => 2, 'name' => 'c', 'parent_id' => 1],
        ]);

        $diff = TreeDiff::between($beforeGen, $afterGen);

        $this->assertCount(1, $diff->added);
        $this->assertSame(2, $diff->added[0]->key);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return Generator<int, array<string, mixed>>
     */
    private function generator(array $rows): Generator
    {
        foreach ($rows as $row) {
            yield $row;
        }
    }
}
