<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Aggregates\Registry;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicate;
use Vusys\NestedSet\Aggregates\Registry\AggregateDefinitionValidator;

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
    private function normalize(?string $sql): ?string
    {
        return AggregateDefinitionValidator::normalizeRawSql($sql);
    }

    #[Test]
    public function null_normalises_to_null(): void
    {
        $this->assertNull($this->normalize(null));
    }

    #[Test]
    public function simple_predicate_round_trips(): void
    {
        $this->assertSame('active = 1', $this->normalize('active = 1'));
    }

    #[Test]
    public function extra_whitespace_collapses_to_single_space(): void
    {
        $this->assertSame('active = 1', $this->normalize('active  =  1'));
        $this->assertSame('active = 1', $this->normalize("active\t=\t1"));
        $this->assertSame('active = 1', $this->normalize("active\n=\n1"));
    }

    #[Test]
    public function leading_and_trailing_whitespace_is_trimmed(): void
    {
        $this->assertSame('active = 1', $this->normalize('  active = 1  '));
    }

    #[Test]
    public function case_is_lowered(): void
    {
        $this->assertSame('active = 1', $this->normalize('ACTIVE = 1'));
        $this->assertSame('status = "open"', $this->normalize('Status = "OPEN"'));
    }

    /**
     * The integration-level concern: two `FilterPredicate::raw` values
     * with whitespace / case drift must produce equal normalised
     * forms so `filtersMatch` accepts them as the same companion.
     */
    #[Test]
    public function filter_predicate_raw_normalises_equally(): void
    {
        $a = FilterPredicate::raw('active = 1', ['active']);
        $b = FilterPredicate::raw('ACTIVE  =  1', ['active']);

        $this->assertSame(
            $this->normalize($a->getRawSql()),
            $this->normalize($b->getRawSql()),
            'Whitespace/case-different raw filters should normalise equally',
        );
    }

    #[Test]
    public function clauses_in_different_order_are_not_considered_equal(): void
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
