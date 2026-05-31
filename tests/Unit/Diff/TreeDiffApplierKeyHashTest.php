<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Diff;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Vusys\NestedSet\Diff\TreeDiffApplier;

/**
 * Direct coverage for the private `keyHash` helper. The public diff
 * surface narrows identity to int|string at the validation layer, so
 * the array / object / unsupported-type branches are defensive — the
 * reviewer asked for stable hashing on those types, and that
 * promise needs an enforcing test.
 */
final class TreeDiffApplierKeyHashTest extends TestCase
{
    public function test_int_key_uses_i_prefix(): void
    {
        $this->assertSame('i:7', $this->hash(7));
    }

    public function test_string_key_uses_s_prefix(): void
    {
        $this->assertSame('s:slug', $this->hash('slug'));
    }

    public function test_null_key_uses_n_prefix(): void
    {
        $this->assertSame('n:', $this->hash(null));
    }

    public function test_array_key_uses_canonical_json_with_a_prefix(): void
    {
        // Same logical map, different declared key order — must hash the same.
        $forward = $this->hash(['b' => 2, 'a' => 1]);
        $reverse = $this->hash(['a' => 1, 'b' => 2]);

        $this->assertSame($forward, $reverse);
        $this->assertStringStartsWith('a:', $forward);
    }

    public function test_array_key_with_nested_associative_value_is_canonical(): void
    {
        $forward = $this->hash(['outer' => ['z' => 1, 'a' => 2]]);
        $reverse = $this->hash(['outer' => ['a' => 2, 'z' => 1]]);

        $this->assertSame($forward, $reverse);
    }

    public function test_object_key_uses_spl_object_hash_with_o_prefix(): void
    {
        $o = new \stdClass;

        $first = $this->hash($o);
        $second = $this->hash($o);

        $this->assertSame($first, $second, 'hashing the same object twice must match');
        $this->assertStringStartsWith('o:', $first);
    }

    public function test_unsupported_type_falls_through_with_t_prefix(): void
    {
        // Resource is the residual mixed case the narrowing chain can't catch
        // at construction time — the canonical-fallthrough has to handle it.
        $fp = fopen('php://memory', 'r');
        $this->assertNotFalse($fp);

        $h = $this->hash($fp);

        $this->assertStringStartsWith('t:', $h);
        fclose($fp);
    }

    private function hash(mixed $key): string
    {
        $r = new ReflectionMethod(TreeDiffApplier::class, 'keyHash');
        $result = $r->invoke(null, $key);

        if (! is_string($result)) {
            throw new \LogicException('keyHash must return a string; this is the invariant under test.');
        }

        return $result;
    }
}
