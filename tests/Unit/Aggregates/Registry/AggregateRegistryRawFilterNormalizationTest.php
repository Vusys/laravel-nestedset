<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Aggregates\Registry;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicate;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;

/**
 * Raw-SQL filter predicates are compared by string equality when the
 * registry decides whether a user-declared Sum/Count is a valid
 * companion for an AVG. Cosmetic whitespace or case differences in
 * the raw SQL string would silently route AVG through auto-promoted
 * internal companions and shadow the user-declared ones. The
 * normaliser collapses whitespace, lower-cases, and trims so
 * semantically-identical raw filters compare equal.
 */
final class AggregateRegistryRawFilterNormalizationTest extends TestCase
{
    /**
     * Invoke the private normaliser via reflection. Keeping it
     * private on the registry is intentional — this test pins the
     * tolerance shape, not the API surface.
     */
    private function normalize(?string $sql): ?string
    {
        $reflection = new ReflectionClass(AggregateRegistry::class);
        $method = $reflection->getMethod('normalizeRawSql');

        /** @var ?string */
        return $method->invoke(null, $sql);
    }

    public function test_null_normalises_to_null(): void
    {
        $this->assertNull($this->normalize(null));
    }

    public function test_simple_predicate_round_trips(): void
    {
        $this->assertSame('active = 1', $this->normalize('active = 1'));
    }

    public function test_extra_whitespace_collapses_to_single_space(): void
    {
        $this->assertSame('active = 1', $this->normalize('active  =  1'));
        $this->assertSame('active = 1', $this->normalize("active\t=\t1"));
        $this->assertSame('active = 1', $this->normalize("active\n=\n1"));
    }

    public function test_leading_and_trailing_whitespace_is_trimmed(): void
    {
        $this->assertSame('active = 1', $this->normalize('  active = 1  '));
    }

    public function test_case_is_lowered(): void
    {
        $this->assertSame('active = 1', $this->normalize('ACTIVE = 1'));
        $this->assertSame('status = "open"', $this->normalize('Status = "OPEN"'));
    }

    /**
     * The integration-level concern: two `FilterPredicate::raw` values
     * with whitespace / case drift must produce equal normalised
     * forms so `filtersMatch` accepts them as the same companion.
     */
    public function test_filter_predicate_raw_normalises_equally(): void
    {
        $a = FilterPredicate::raw('active = 1', ['active']);
        $b = FilterPredicate::raw('ACTIVE  =  1', ['active']);

        $this->assertSame(
            $this->normalize($a->getRawSql()),
            $this->normalize($b->getRawSql()),
            'Whitespace/case-different raw filters should normalise equally',
        );
    }

    public function test_clauses_in_different_order_are_not_considered_equal(): void
    {
        // Out of scope: the normaliser absorbs cosmetic drift but
        // doesn't parse SQL. Reordered AND clauses stay distinct —
        // anything else would require a real SQL parser.
        $this->assertNotSame(
            $this->normalize('a = 1 AND b = 2'),
            $this->normalize('b = 2 AND a = 1'),
        );
    }
}
