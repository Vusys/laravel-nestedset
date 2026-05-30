<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Functions;

use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Aggregates\Sql\SqliteBitwiseAggregates;
use Vusys\NestedSet\Tests\Fixtures\Models\BitwiseArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Coverage for the fresh-read side of bitwise rollups — `withFreshAggregates`
 * and `aggregateErrors`, both of which evaluate the stored aggregate
 * SQL (`BIT_OR`, `BIT_AND`, `BIT_XOR`) against the live subtree.
 *
 * On MySQL / MariaDB / PostgreSQL these aggregates are server natives,
 * but SQLite has no built-in bitwise aggregate, so the package
 * installs them as user-defined aggregates via PDO::sqliteCreateAggregate
 * in {@see SqliteBitwiseAggregates}.
 * Until this file existed, no test ever evaluated those UDAs at runtime
 * — the maintenance path keeps stored columns up to date via PHP-side
 * deltas, never going through the SQL aggregate. As a result, the
 * UDA registration code, the step / finalize callbacks, and the
 * NULL-skip / first-seed semantics were entirely unverified end-to-end.
 *
 * Tree:
 *
 *   Root(0001)
 *   ├── A(0010)
 *   │   └── A1(0100)
 *   └── B(1000)
 *
 * Expected rollups (inclusive):
 *   OR : 0001 | 0010 | 0100 | 1000 = 1111
 *   AND: 0001 & 0010 & 0100 & 1000 = 0000
 *   XOR: 0001 ^ 0010 ^ 0100 ^ 1000 = 1111
 */
final class BitwiseFreshReadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    /** @return array{root: BitwiseArea, a: BitwiseArea, a1: BitwiseArea, b: BitwiseArea} */
    private function buildTree(): array
    {
        $root = new BitwiseArea(['name' => 'Root', 'feature_bits' => 0b0001]);
        $root->saveAsRoot();

        $a = new BitwiseArea(['name' => 'A', 'feature_bits' => 0b0010]);
        $a->appendToNode($root)->save();

        $a1 = new BitwiseArea(['name' => 'A1', 'feature_bits' => 0b0100]);
        $a1->appendToNode($a->refresh())->save();

        $b = new BitwiseArea(['name' => 'B', 'feature_bits' => 0b1000]);
        $b->appendToNode($root->refresh())->save();

        return ['root' => $root->refresh(), 'a' => $a->refresh(), 'a1' => $a1->refresh(), 'b' => $b->refresh()];
    }

    public function test_with_fresh_aggregates_evaluates_bitwise_subqueries(): void
    {
        $t = $this->buildTree();

        $row = BitwiseArea::query()
            ->withFreshAggregates(['features_or', 'features_and', 'features_xor'])
            ->where('id', $t['root']->id)
            ->firstOrFail();

        $this->assertSame(0b1111, (int) $row->features_or);
        $this->assertSame(0b0000, (int) $row->features_and);
        $this->assertSame(0b1111, (int) $row->features_xor);
    }

    public function test_with_fresh_aggregates_for_a_subtree(): void
    {
        $t = $this->buildTree();

        // Subtree under A is just {A, A1}: 0010 | 0100 = 0110, AND = 0000, XOR = 0110.
        $row = BitwiseArea::query()
            ->withFreshAggregates(['features_or', 'features_and', 'features_xor'])
            ->where('id', $t['a']->id)
            ->firstOrFail();

        $this->assertSame(0b0110, (int) $row->features_or);
        $this->assertSame(0b0000, (int) $row->features_and);
        $this->assertSame(0b0110, (int) $row->features_xor);
    }

    public function test_with_fresh_aggregates_on_a_leaf(): void
    {
        $t = $this->buildTree();

        // Leaf B is just itself: 1000 / 1000 / 1000.
        $row = BitwiseArea::query()
            ->withFreshAggregates(['features_or', 'features_and', 'features_xor'])
            ->where('id', $t['b']->id)
            ->firstOrFail();

        $this->assertSame(0b1000, (int) $row->features_or);
        $this->assertSame(0b1000, (int) $row->features_and);
        $this->assertSame(0b1000, (int) $row->features_xor);
    }

    public function test_aggregate_errors_evaluates_bitwise_subqueries(): void
    {
        $this->buildTree();

        // aggregateErrors runs the same fresh aggregates and compares them
        // to the stored columns. Zero errors here means the SQL fold and
        // the PHP delta fold produced identical results.
        $errors = BitwiseArea::aggregateErrors();

        $this->assertSame(0, $errors['features_or'] ?? -1);
        $this->assertSame(0, $errors['features_and'] ?? -1);
        $this->assertSame(0, $errors['features_xor'] ?? -1);
    }
}
