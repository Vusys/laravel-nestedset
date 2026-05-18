<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Support;

/**
 * Per-process knobs for the seeded fuzzers.
 *
 * Default behaviour is a regression-tight, fast set of seeds — the
 * same values the fuzzers ship with. Set env vars to override for
 * exploratory runs (e.g. `FUZZER_SEEDS=1,2,3,4,5,6 FUZZER_STEPS=200`).
 *
 * Vars:
 *   FUZZER_SEEDS   comma-separated seed integers (overrides defaults).
 *                  If set to `random` the helper produces N random
 *                  seeds where N = FUZZER_SEED_COUNT (default 5).
 *   FUZZER_SEED_COUNT  how many random seeds to emit when SEEDS=random.
 *   FUZZER_STEPS   override the per-seed step count.
 *   FUZZER_RUNS    override the per-seed run count (bulkInsert fuzzer).
 *
 * Read once per process; values are deterministic across calls.
 */
final class FuzzerConfig
{
    /**
     * @param  list<int>  $defaults
     * @return list<int>
     */
    public static function seeds(array $defaults): array
    {
        $raw = getenv('FUZZER_SEEDS');
        if ($raw === false || $raw === '') {
            return $defaults;
        }

        if ($raw === 'random') {
            $count = self::intEnv('FUZZER_SEED_COUNT', count($defaults));
            $seeds = [];
            for ($i = 0; $i < $count; $i++) {
                $seeds[] = random_int(1, PHP_INT_MAX);
            }

            return $seeds;
        }

        $out = [];
        foreach (explode(',', $raw) as $part) {
            $trimmed = trim($part);
            if (! is_numeric($trimmed)) {
                continue;
            }
            $out[] = (int) $trimmed;
        }

        return $out === [] ? $defaults : $out;
    }

    public static function steps(int $default): int
    {
        return self::intEnv('FUZZER_STEPS', $default);
    }

    public static function runs(int $default): int
    {
        return self::intEnv('FUZZER_RUNS', $default);
    }

    private static function intEnv(string $name, int $default): int
    {
        $raw = getenv($name);
        if ($raw === false || ! is_numeric($raw)) {
            return $default;
        }

        return max(1, (int) $raw);
    }
}
