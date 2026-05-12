<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Performance;

use Vusys\NestedSet\Tests\Performance\Bench\Benchmark;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Base class for performance benchmarks.
 *
 * Differs from the regular feature `TestCase` in two ways:
 *
 *   - Tree-integrity check skipped at tearDown. Perf tests build large
 *     trees and tearing them through the integrity check would dominate
 *     the runtime they're trying to measure.
 *   - `bench()` helper for clean wall-clock + query-count capture.
 *
 * Results print to stdout (PHPUnit's `--testdox` shows them inline).
 * No threshold assertions yet — Phase J establishes baselines, future
 * phases compare against them.
 */
abstract class PerformanceTestCase extends TestCase
{
    /** Perf tests build large trees; the integrity check would dominate runtime. */
    protected bool $allowBrokenTreeAtTearDown = true;

    /** @var list<Benchmark> */
    private array $benchmarks = [];

    /**
     * Read the desired top scale from the PERF_SCALE_MAX env var.
     * Defaults to 10,000 — large enough to be informative locally,
     * small enough to run in CI in a minute or two.
     */
    protected function maxScale(): int
    {
        $env = getenv('PERF_SCALE_MAX');

        if ($env === false || $env === '' || ! is_numeric($env)) {
            return 10_000;
        }

        return max(100, (int) $env);
    }

    /**
     * Returns the scales the current test should exercise, capped at
     * {@see maxScale()}. Default progression is 100 → 1K → 10K → 100K
     * → 1M; tests should iterate this list and skip larger entries
     * when the harness is running with a lower cap.
     *
     * @return list<int>
     */
    protected function scales(): array
    {
        $all = [100, 1_000, 10_000, 100_000, 1_000_000];
        $cap = $this->maxScale();

        return array_values(array_filter($all, static fn (int $n): bool => $n <= $cap));
    }

    protected function bench(string $label, callable $operation): Benchmark
    {
        $result = Benchmark::run($label, $operation);
        $this->benchmarks[] = $result;

        return $result;
    }

    /**
     * Phase J's benchmarks don't assert thresholds yet (just print
     * results). Tests still need an assertion to avoid PHPUnit's
     * "risky test — no assertions" warning; calling this at the end
     * of a bench loop asserts that at least one benchmark ran.
     */
    protected function assertBenchmarksRan(): void
    {
        $this->assertGreaterThan(0, count($this->benchmarks), 'expected at least one benchmark to have run');
    }

    protected function tearDown(): void
    {
        if ($this->benchmarks !== []) {
            $driver = (string) env('DB_CONNECTION', 'sqlite');

            fwrite(STDOUT, "\n[".strtoupper($driver).'] '.static::class."\n");
            foreach ($this->benchmarks as $b) {
                fwrite(STDOUT, '  '.$b->toLine()."\n");
            }
        }

        parent::tearDown();
    }
}
