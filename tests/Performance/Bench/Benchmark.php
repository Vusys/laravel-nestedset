<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Performance\Bench;

use Illuminate\Support\Facades\DB;

/**
 * Captures wall-clock time and query count around a single operation.
 *
 * The benchmark harness deliberately doesn't assert thresholds yet —
 * Phase J's job is to establish baselines we can measure regressions
 * against later. Results print as test output; future phases will
 * persist them as JSON and compare.
 */
final readonly class Benchmark
{
    public function __construct(
        public string $label,
        public float $wallSeconds,
        public int $queries,
    ) {}

    /**
     * Run $operation, return a populated Benchmark.
     */
    public static function run(string $label, callable $operation): self
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $start = microtime(true);

        try {
            $operation();
        } finally {
            $wall = microtime(true) - $start;
            DB::disableQueryLog();
        }

        return new self(
            label: $label,
            wallSeconds: $wall,
            queries: count(DB::getQueryLog()),
        );
    }

    public function toLine(): string
    {
        return sprintf(
            '%-60s  %7.3f ms  %5d queries',
            $this->label,
            $this->wallSeconds * 1000,
            $this->queries,
        );
    }
}
