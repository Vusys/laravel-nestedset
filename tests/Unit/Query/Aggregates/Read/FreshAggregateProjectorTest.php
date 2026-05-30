<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Query\Aggregates\Read;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Vusys\NestedSet\Query\Aggregates\Read\FreshAggregateProjector;

/**
 * Pure-unit coverage for the MySQL version-string check that gates the
 * LATERAL join path. The wider `supportsLateral()` requires a live
 * Connection + PDO whose `ATTR_SERVER_VERSION` is hard to spoof at
 * arbitrary versions, but the version-parse step is self-contained and
 * worth pinning case-by-case: it decides between LATERAL (fast) and
 * the correlated fallback (always-correct) for an entire backend
 * family.
 *
 * Until this file existed, only one branch of the version comparison
 * ran in CI (whatever version MySQL 8.0 reports there), leaving the
 * `> 8` / `=== 8` / `minor > 0` / `patch >= 14` lattice unvalidated.
 *
 * The method under test is private; we reach it via reflection rather
 * than widening its visibility — its inputs/outputs are stable but its
 * existence is an implementation detail of the projector.
 */
final class FreshAggregateProjectorTest extends TestCase
{
    private static function call(?string $version): bool
    {
        $method = new ReflectionMethod(FreshAggregateProjector::class, 'mysqlVersionSupportsLateral');

        return (bool) $method->invoke(null, $version);
    }

    /**
     * @return iterable<string, array{0: ?string, 1: bool}>
     */
    public static function versions(): iterable
    {
        // Null / unparseable input.
        yield 'null version' => [null,                                  false];
        yield 'empty string' => ['',                                    false];
        yield 'non-numeric label only' => ['unknown',                             false];

        // MariaDB family — must always be rejected.
        yield 'mariadb 10.5.0' => ['10.5.0-MariaDB',                      false];
        yield 'mariadb 11.4.0' => ['11.4.0-MariaDB-1:11.4.0+maria',       false];
        yield 'mariadb mixed case' => ['10.6.18-MaRiAdB-log',                 false];
        yield 'mariadb lowercase token' => ['mariadb 11.0.0',                      false];

        // MySQL versions either side of the 8.0.14 LATERAL cut-off.
        yield 'mysql 5.7.44' => ['5.7.44',                              false];
        yield 'mysql 8.0.0' => ['8.0.0',                               false];
        yield 'mysql 8.0.13' => ['8.0.13',                              false];
        yield 'mysql 8.0.14 (cutoff inclusive)' => ['8.0.14',                              true];
        yield 'mysql 8.0.99 (any later 8.0.x)' => ['8.0.99',                              true];
        yield 'mysql 8.1.0 (minor > 0)' => ['8.1.0',                               true];
        yield 'mysql 8.4.0' => ['8.4.0',                               true];
        yield 'mysql 9.0.0 (major > 8)' => ['9.0.0',                               true];
        yield 'mysql 10.0.0 hypothetical' => ['10.0.0',                              true];

        // Version-string shapes that still round-trip through the regex.
        yield 'mysql 8.0 (no patch)' => ['8.0',                                 false];
        yield 'mysql 8.0-mysql tag (no patch)' => ['8.0-mysql-community',                 false];
        yield 'mysql 8.0.14 with suffix' => ['8.0.14-mysql-community',              true];
        yield 'mysql 8.4.2 with suffix' => ['8.4.2-log',                           true];
    }

    #[DataProvider('versions')]
    public function test_mysql_version_supports_lateral(?string $version, bool $expected): void
    {
        $this->assertSame($expected, self::call($version));
    }
}
