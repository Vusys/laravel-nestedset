<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Aggregates\Filters;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\Filters\BoundFragment;
use Vusys\NestedSet\Aggregates\Filters\FragmentSplicer;

final class FragmentSplicerTest extends TestCase
{
    #[Test]
    public function null_filter_passes_through_with_no_bindings(): void
    {
        $fragment = FragmentSplicer::splice(
            null,
            static fn (?string $pred): string => $pred === null ? 'SUM(x)' : "SUM(CASE WHEN {$pred} THEN x END)",
        );

        $this->assertSame('SUM(x)', $fragment->sql);
        $this->assertSame([], $fragment->bindings);
    }

    #[Test]
    public function single_splice_passes_bindings_through_once(): void
    {
        $filter = new BoundFragment('x = ?', [1]);

        $fragment = FragmentSplicer::splice(
            $filter,
            static fn (?string $pred): string => "SUM(CASE WHEN {$pred} THEN y END)",
        );

        $this->assertSame('SUM(CASE WHEN x = ? THEN y END)', $fragment->sql);
        $this->assertSame([1], $fragment->bindings);
    }

    #[Test]
    public function n_splices_repeat_bindings_n_times(): void
    {
        $filter = new BoundFragment('x = ? AND y = ?', [1, 'foo']);

        $fragment = FragmentSplicer::splice(
            $filter,
            static fn (?string $pred): string => "SUM(CASE WHEN {$pred} THEN z END) + COUNT(CASE WHEN {$pred} THEN 1 END) + AVG(CASE WHEN {$pred} THEN z END)",
        );

        $this->assertSame(
            'SUM(CASE WHEN x = ? AND y = ? THEN z END) + COUNT(CASE WHEN x = ? AND y = ? THEN 1 END) + AVG(CASE WHEN x = ? AND y = ? THEN z END)',
            $fragment->sql,
        );
        $this->assertSame([1, 'foo', 1, 'foo', 1, 'foo'], $fragment->bindings);
    }

    #[Test]
    public function bindingless_filter_skips_repetition(): void
    {
        $filter = BoundFragment::literal('active = 1');

        $fragment = FragmentSplicer::splice(
            $filter,
            static fn (?string $pred): string => "CASE WHEN {$pred} THEN x END + CASE WHEN {$pred} THEN y END",
        );

        $this->assertSame('CASE WHEN active = 1 THEN x END + CASE WHEN active = 1 THEN y END', $fragment->sql);
        $this->assertSame([], $fragment->bindings);
    }
}
