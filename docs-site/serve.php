<?php

declare(strict_types=1);

/**
 * Local preview server.
 *
 *   php serve.php [--port=8000] [--no-watch]
 *
 * Does three things:
 *   1. Runs an initial build.
 *   2. Starts PHP's built-in web server pointed at ../site.
 *   3. Watches docs/ and docs-site/ for changes and rebuilds on save.
 *
 * The browser polls /_build.json for the build timestamp and auto-reloads
 * when it changes (see public/app.js).
 */
$opts = getopt('', ['port::', 'no-watch']);
$port = filter_var(
    $opts['port'] ?? 8000,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 65535]]
);
if ($port === false) {
    fwrite(STDERR, "Invalid --port value. Use an integer between 1 and 65535.\n");
    exit(1);
}
$watch = ! array_key_exists('no-watch', $opts);

$repoRoot = realpath(__DIR__.'/..');
if ($repoRoot === false) {
    fwrite(STDERR, 'fatal: failed to resolve repository root from '.__DIR__."\n");
    exit(1);
}
$siteDir = $repoRoot.'/site';
$docsDir = $repoRoot.'/docs';
$srcDir = __DIR__;

if (! build($repoRoot)) {
    exit(1);
}

$server = startServer($siteDir, $port);
register_shutdown_function(function () use (&$server) {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
});

if (! $watch) {
    echo "Watching disabled. Hit Ctrl+C to stop.\n";
    waitForever($server);
    exit;
}

echo "Watching {$docsDir} and {$srcDir} for changes...\n";

$state = snapshot([$docsDir, $srcDir.'/templates', $srcDir.'/public', $srcDir.'/build.php']);

while (true) {
    usleep(400_000);

    $procStatus = proc_get_status($server);
    if (! $procStatus['running']) {
        echo "Server stopped.\n";
        break;
    }

    $next = snapshot([$docsDir, $srcDir.'/templates', $srcDir.'/public', $srcDir.'/build.php']);
    if ($next !== $state) {
        echo "\nChange detected, rebuilding...\n";
        build($repoRoot);
        $state = $next;
    }
}

function build(string $repoRoot): bool
{
    $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/build.php');
    $start = microtime(true);
    passthru($cmd, $code);
    $ms = (int) ((microtime(true) - $start) * 1000);
    if ($code !== 0) {
        echo "Build failed (exit {$code})\n";

        return false;
    }

    echo "Build OK in {$ms}ms\n";

    return true;
}

function startServer(string $docroot, int $port)
{
    if (! is_dir($docroot)) {
        fwrite(STDERR, "site/ does not exist; did the initial build fail?\n");
        exit(1);
    }

    echo "Serving {$docroot} on http://localhost:{$port}\n";
    $cmd = sprintf(
        '%s -S 127.0.0.1:%d -t %s',
        escapeshellarg(PHP_BINARY),
        $port,
        escapeshellarg($docroot)
    );

    $desc = [
        0 => ['pipe', 'r'],
        1 => STDOUT,
        2 => STDOUT,
    ];

    $proc = proc_open($cmd, $desc, $pipes);
    if (! is_resource($proc)) {
        fwrite(STDERR, "Failed to start dev server.\n");
        exit(1);
    }

    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }

    return $proc;
}

function waitForever($server): void
{
    while (true) {
        $status = proc_get_status($server);
        if (! $status['running']) {
            break;
        }
        sleep(1);
    }
}

function snapshot(array $paths): string
{
    $hash = hash_init('xxh3');
    foreach ($paths as $path) {
        if (is_file($path)) {
            hash_update($hash, $path.':'.filemtime($path));

            continue;
        }
        if (! is_dir($path)) {
            continue;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($items as $item) {
            if ($item->isFile()) {
                hash_update($hash, $item->getPathname().':'.$item->getMTime());
            }
        }
    }

    return hash_final($hash);
}
