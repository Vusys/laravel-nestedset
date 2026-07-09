<?php

declare(strict_types=1);

/*
 * Pre-create the per-worker databases ParaTest needs on the networked
 * engines. Each ParaTest worker exports TEST_TOKEN (1..N) and
 * tests/TestCase.php appends it to DB_DATABASE (`testing_1`, `testing_2`,
 * …) so workers never share a schema. Those databases must exist before
 * the run — testbench migrates into them but does not create them.
 *
 * Driver-agnostic on purpose: it uses PDO (pdo_mysql / pdo_pgsql, both
 * installed by the CI php-setup action) so there's no dependency on the
 * mysql/psql CLI clients being present on the runner. SQLite is a no-op —
 * `:memory:` is per-connection and already isolates every worker.
 *
 * Usage (env-driven, mirrors the CI test step):
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
 *   DB_DATABASE=testing DB_USERNAME=root DB_PASSWORD=password \
 *   PARATEST_WORKERS=4 php tooling/create-worker-databases.php
 */

$connection = getenv('DB_CONNECTION') ?: 'sqlite';

if ($connection === 'sqlite') {
    fwrite(STDOUT, "sqlite: nothing to create (:memory: is per-worker).\n");
    exit(0);
}

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: ($connection === 'pgsql' ? '5432' : '3306');
$base = getenv('DB_DATABASE') ?: 'testing';
$user = getenv('DB_USERNAME') ?: ($connection === 'pgsql' ? 'postgres' : 'root');
$pass = getenv('DB_PASSWORD') ?: '';
$workers = max(1, (int) (getenv('PARATEST_WORKERS') ?: '4'));

$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

if ($connection === 'pgsql') {
    // PostgreSQL has no CREATE DATABASE IF NOT EXISTS and forbids it inside
    // a transaction. Connect to the always-present `postgres` maintenance
    // database, then guard each create with a pg_database existence check.
    $pdo = new PDO("pgsql:host={$host};port={$port};dbname=postgres", $user, $pass, $options);

    for ($token = 1; $token <= $workers; $token++) {
        $name = "{$base}_{$token}";
        $exists = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
        $exists->execute([$name]);

        if ($exists->fetchColumn() === false) {
            $pdo->exec('CREATE DATABASE "'.str_replace('"', '""', $name).'"');
        }

        fwrite(STDOUT, "pgsql: ensured {$name}\n");
    }

    exit(0);
}

// mysql + mariadb share the mysql PDO driver. Connect without a dbname
// (the base `testing` may not exist yet) and lean on IF NOT EXISTS.
$pdo = new PDO("mysql:host={$host};port={$port}", $user, $pass, $options);

for ($token = 1; $token <= $workers; $token++) {
    $name = "{$base}_{$token}";
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.str_replace('`', '``', $name).'`');
    fwrite(STDOUT, "mysql: ensured {$name}\n");
}
