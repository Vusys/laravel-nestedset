<?php

declare(strict_types=1);

// Converts the Performance suite's `::bench-end::` stderr lines into
// Bencher Metric Format (BMF) JSON. Reads stdin, writes stdout.
// Consumed by .github/workflows/bencher.yml, which feeds the result
// to `bencher run --adapter json` for continuous perf tracking.
//
// Input line shape (emitted by tests/Performance/PerformanceTestCase.php):
//   ::bench-end::   <label>  <label-padded-60>     XXX.XXX ms     YY queries
//
// The label appears twice (once plain, once right-padded by sprintf)
// — we capture the plain one as the benchmark name.

$result = [];

while (($line = fgets(STDIN)) !== false) {
    if (! str_contains($line, '::bench-end::')) {
        continue;
    }

    if (! preg_match(
        '/::bench-end::\s+(\S.*?)\s\s+\S.*?\s+([0-9]+\.[0-9]+)\s+ms\s+([0-9]+)\s+queries/',
        $line,
        $match,
    )) {
        continue;
    }

    $label = trim($match[1]);

    $result[$label] = [
        'latency' => ['value' => (float) $match[2]],
        'queries' => ['value' => (float) $match[3]],
    ];
}

if ($result === []) {
    fwrite(STDERR, "perf-to-bmf: no ::bench-end:: lines found on stdin\n");
    exit(1);
}

$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if ($json === false) {
    fwrite(STDERR, 'perf-to-bmf: JSON encoding failed: '.json_last_error_msg()."\n");
    exit(1);
}

echo $json."\n";
