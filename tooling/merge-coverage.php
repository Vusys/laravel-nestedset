<?php

declare(strict_types=1);

// Union-merges several Clover XML coverage reports. A statement line
// counts as covered if ANY report executed it — so running the suite
// once per database backend (see tooling/db-compose.yml) and merging the
// per-backend reports yields the true package-wide line coverage, with
// backend-specific SQL paths attributed to the backend that exercises
// them. Mirrors how Codecov merges the per-flag uploads in CI.
//
// Usage:
//   php tooling/merge-coverage.php <clover.xml> [<clover.xml> ...]

$paths = array_slice($argv, 1);

if ($paths === []) {
    fwrite(STDERR, "Usage: merge-coverage.php <clover.xml> [<clover.xml> ...]\n");
    exit(1);
}

/** @var array<string, array<int, bool>> $files  file path => [line number => covered by any report] */
$files = [];

foreach ($paths as $path) {
    if (! is_file($path)) {
        fwrite(STDERR, "Clover file not found: {$path}\n");
        exit(1);
    }

    $xml = simplexml_load_file($path);
    if ($xml === false) {
        fwrite(STDERR, "Failed to parse Clover XML at {$path}\n");
        exit(1);
    }

    foreach ($xml->xpath('//file') ?? [] as $fileNode) {
        $name = (string) $fileNode['name'];
        if ($name === '') {
            continue;
        }

        foreach ($fileNode->line as $line) {
            if ((string) $line['type'] !== 'stmt') {
                continue;
            }

            $num = (int) $line['num'];
            $covered = (int) $line['count'] > 0;
            $files[$name][$num] = ($files[$name][$num] ?? false) || $covered;
        }
    }
}

$totalStatements = 0;
$coveredStatements = 0;

/** @var list<array{int, string, int, int, list<int>}> $gaps */
$gaps = [];

foreach ($files as $name => $lines) {
    $total = count($lines);
    $covered = count(array_filter($lines));
    $totalStatements += $total;
    $coveredStatements += $covered;

    if ($total - $covered > 0) {
        /** @var list<int> $missing */
        $missing = array_keys(array_filter($lines, static fn (bool $isCovered): bool => ! $isCovered));
        $gaps[] = [$total - $covered, $name, $total, $covered, $missing];
    }
}

$pct = $totalStatements > 0 ? 100 * $coveredStatements / $totalStatements : 0.0;

printf(
    "MERGED line coverage: %d/%d = %.2f%%  (missing %d)\n\n",
    $coveredStatements,
    $totalStatements,
    $pct,
    $totalStatements - $coveredStatements,
);

usort($gaps, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

echo "Remaining gaps after merging all reports:\n";
foreach (array_slice($gaps, 0, 30) as [$uncovered, $name, $total, $covered, $missing]) {
    printf("  %4d  %5.1f%%  %s\n", $uncovered, 100 * $covered / $total, shortenPath($name));
    printf("        %s\n", formatRanges($missing));
}

function shortenPath(string $path): string
{
    $pos = strpos($path, '/src/');

    return $pos === false ? $path : substr($path, $pos + 5);
}

/**
 * Collapse a sorted list of line numbers into compact ranges, e.g.
 * `[1, 2, 3, 7]` → `"1-3,7"`.
 *
 * @param  list<int>  $nums
 */
function formatRanges(array $nums): string
{
    sort($nums);

    if ($nums === []) {
        return '';
    }

    $ranges = [];
    $start = $prev = $nums[0];

    foreach (array_slice($nums, 1) as $n) {
        if ($n === $prev + 1) {
            $prev = $n;

            continue;
        }

        $ranges[] = $start === $prev ? (string) $start : "{$start}-{$prev}";
        $start = $prev = $n;
    }

    $ranges[] = $start === $prev ? (string) $start : "{$start}-{$prev}";

    return implode(',', $ranges);
}
