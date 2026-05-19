<?php

declare(strict_types=1);

// Computes README badge metrics and emits shields.io endpoint-format JSON
// files. Consumed by .github/workflows/badges.yml, which publishes the
// resulting JSONs to the orphan `badges` branch.
//
// Usage:
//   php compute-badge-metrics.php <junit.xml> <output-dir> <matrix-cells>

[$_script, $junitPath, $outDir, $matrixCellsArg] = $argv + [null, null, null, null];

if ($junitPath === null || $outDir === null || $matrixCellsArg === null) {
    fwrite(STDERR, "Usage: compute-badge-metrics.php <junit.xml> <output-dir> <matrix-cells>\n");
    exit(1);
}

$matrixCells = (int) $matrixCellsArg;

if (! is_file($junitPath)) {
    fwrite(STDERR, "JUnit file not found: {$junitPath}\n");
    exit(1);
}

if (! is_dir($outDir) && ! mkdir($outDir, 0o777, true) && ! is_dir($outDir)) {
    fwrite(STDERR, "Failed to create output dir: {$outDir}\n");
    exit(1);
}

$xml = simplexml_load_file($junitPath);
if ($xml === false) {
    fwrite(STDERR, "Failed to parse JUnit XML at {$junitPath}\n");
    exit(1);
}

// PHPUnit emits <testsuites> wrapping one or more top-level <testsuite>
// nodes. Each top-level node carries aggregate `tests` and `assertions`
// attributes for its entire subtree, so summing the top level is correct.
$tests = 0;
$assertions = 0;
foreach ($xml->testsuite as $suite) {
    $tests += (int) $suite['tests'];
    $assertions += (int) $suite['assertions'];
}

$srcLines = countPhpLines('src');
$testLines = countPhpLines('tests');
$ratio = $srcLines > 0 ? round($testLines / $srcLines, 1) : 0.0;

writeBadge("{$outDir}/tests.json", 'tests', number_format($tests), 'blue');
writeBadge("{$outDir}/assertions.json", 'assertions', number_format($assertions), 'blueviolet');
writeBadge("{$outDir}/test-ratio.json", 'test LOC', $ratio.'× src', 'blueviolet');
writeBadge("{$outDir}/matrix.json", 'CI matrix', $matrixCells.' configurations', 'success');

printf("tests:      %s\n", number_format($tests));
printf("assertions: %s\n", number_format($assertions));
printf("src LOC:    %s\n", number_format($srcLines));
printf("test LOC:   %s\n", number_format($testLines));
printf("ratio:      %s× (test:src)\n", $ratio);
printf("matrix:     %d configurations\n", $matrixCells);

function countPhpLines(string $dir): int
{
    if (! is_dir($dir)) {
        return 0;
    }

    $total = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile()) {
            continue;
        }
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        if ($contents === false || $contents === '') {
            continue;
        }

        $total += substr_count($contents, "\n");
        if (! str_ends_with($contents, "\n")) {
            $total += 1;
        }
    }

    return $total;
}

function writeBadge(string $path, string $label, string $message, string $color): void
{
    $payload = [
        'schemaVersion' => 1,
        'label' => $label,
        'message' => $message,
        'color' => $color,
        'cacheSeconds' => 3600,
    ];

    file_put_contents(
        $path,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
    );
}
