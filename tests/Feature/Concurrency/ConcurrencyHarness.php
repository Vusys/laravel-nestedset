<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Concurrency;

use Illuminate\Support\Facades\DB;

/**
 * Helper trait for the fork-based concurrency tests in this directory.
 * They rely on pcntl_fork to launch N child workers that hit the
 * database in parallel — a single-process loop can't expose row-level
 * locking races because PHP serialises its own statements on one
 * connection.
 *
 * The harness skips when:
 *  - pcntl_fork isn't available (Windows, php-fpm SAPI, locked-down host).
 *  - The backend doesn't support row locking (`sqlite`). FOR UPDATE is
 *    a no-op on SQLite and the database itself is single-writer, so
 *    the contention window we're trying to catch can't exist.
 *  - SQLite is also unsharable across fork(): the parent's `:memory:`
 *    database doesn't reach the child.
 */
trait ConcurrencyHarness
{
    /**
     * Bail out unless the runtime + DB combination can actually
     * exercise multi-writer contention. Call from the top of every
     * concurrency test.
     */
    protected function requireForkableMultiWriterBackend(): void
    {
        if (! \function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork unavailable on this SAPI.');
        }

        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $this->markTestSkipped('SQLite has no row-level locking and the in-memory database does not survive fork().');
        }
    }

    /**
     * Forks $workers child processes and runs $work in each. Returns
     * the list of child exit codes so the caller can assert "all zero".
     * Children that throw write the message to STDERR and exit non-zero.
     *
     * Children purge the inherited PDO connection before doing any
     * work — a PDO socket inherited across fork() is unsafe to share,
     * and Laravel's connection manager hands out a fresh one on next
     * use after `DB::purge()`.
     *
     * @param  \Closure(int): void  $work  Receives the worker index (0..N-1).
     * @return list<int> Exit codes, one per worker in spawn order.
     */
    protected function runConcurrentWorkers(int $workers, \Closure $work): array
    {
        $pids = [];
        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                foreach ($pids as $existing) {
                    posix_kill($existing, SIGTERM);
                    pcntl_waitpid($existing, $status);
                }
                $this->fail('pcntl_fork failed.');
            }

            if ($pid === 0) {
                // Child. Reseed RNG so workers don't all pick the same
                // "random" values for retry-jitter or test data.
                mt_srand((int) (microtime(true) * 1_000_000) ^ getmypid());

                try {
                    DB::purge();
                    $work($i);
                    exit(0);
                } catch (\Throwable $e) {
                    fwrite(STDERR, sprintf(
                        "[worker %d pid=%d] %s: %s\n",
                        $i,
                        getmypid(),
                        $e::class,
                        $e->getMessage(),
                    ));
                    exit(1);
                }
            }

            $pids[] = $pid;
        }

        $exits = [];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            if (! pcntl_wifexited($status)) {
                $exits[] = -1;

                continue;
            }
            $code = pcntl_wexitstatus($status);
            $exits[] = $code === false ? -1 : $code;
        }

        // Parent has been forked through; the connection it holds is
        // stale once any child closed it. Purge so subsequent parent
        // queries get a fresh PDO.
        DB::purge();

        return $exits;
    }
}
