<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Query\Aggregates\Read;

use Closure;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\SQLiteConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Definitions\CompanionSourceTransform;
use Vusys\NestedSet\Aggregates\Filters\BoundFragment;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicate;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Query\Aggregates\Read\AggregateSqlFragments;
use Vusys\NestedSet\Tests\Support\DriverFakedConnection;

/**
 * Pure-unit SQL-snapshot tests for the fresh-aggregate read-path
 * fragment builder. The connection is a real in-memory SQLite, wrapped
 * in {@see DriverFakedConnection} when a non-sqlite driver name needs to
 * be spoofed (PDO::quote still uses the real driver).
 */
final class AggregateSqlFragmentsTest extends TestCase
{
    private Connection $sqliteConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->sqliteConnection = $capsule->getConnection();
    }

    private function fakeDriver(string $driver): Connection
    {
        if ($driver === 'sqlite') {
            return $this->sqliteConnection;
        }

        return new DriverFakedConnection($this->sqliteConnection, $driver);
    }

    /**
     * Inline a fragment's bindings into its SQL — used by snapshot
     * assertions that pre-date the bindings refactor. Reproduces the
     * previous inline-literal shape so existing test datasets keep
     * their pre-bindings expected strings.
     */
    private function inline(BoundFragment $fragment): string
    {
        $sql = $fragment->sql;
        foreach ($fragment->bindings as $value) {
            $pos = strpos($sql, '?');
            if ($pos === false) {
                break;
            }
            if ($value === null) {
                $literal = 'NULL';
            } elseif (is_bool($value)) {
                $literal = $value ? 'TRUE' : 'FALSE';
            } elseif (is_int($value) || is_float($value)) {
                $literal = (string) $value;
            } else {
                $literal = "'".$value."'";
            }
            $sql = substr_replace($sql, $literal, $pos, 1);
        }

        return $sql;
    }

    /**
     * Main entry point: unfiltered + equality/notnull-filtered aggregate
     * expressions across every function kind.
     *
     * @param  Closure(): AggregateDefinition  $makeDefinition
     */
    #[DataProvider('aggregateExpressionCases')]
    public function test_aggregate_expression(Closure $makeDefinition, string $qualifier, string $expected): void
    {
        $fragment = AggregateSqlFragments::aggregateExpression($makeDefinition(), $qualifier, $this->sqliteConnection);

        $this->assertSame($expected, $this->inline($fragment));
    }

    /**
     * @return iterable<string, array{0: Closure(): AggregateDefinition, 1: string, 2: string}>
     */
    public static function aggregateExpressionCases(): iterable
    {
        yield 'sum body identity' => [
            static fn (): AggregateDefinition => Aggregate::sum('x')->into('col'),
            'd.', 'COALESCE(SUM(d.x), 0)',
        ];

        // Unfiltered ladder — every function kind through the main match.
        yield 'unfiltered count star' => [
            static fn (): AggregateDefinition => Aggregate::count()->into('col'),
            'd.', 'COUNT(*)',
        ];

        yield 'unfiltered count source' => [
            static fn (): AggregateDefinition => Aggregate::count('x')->into('col'),
            'd.', 'COUNT(d.x)',
        ];

        yield 'unfiltered avg' => [
            static fn (): AggregateDefinition => Aggregate::avg('x')->into('col'),
            'd.', 'AVG(d.x)',
        ];

        yield 'unfiltered min' => [
            static fn (): AggregateDefinition => Aggregate::min('x')->into('col'),
            'd.', 'MIN(d.x)',
        ];

        yield 'unfiltered max' => [
            static fn (): AggregateDefinition => Aggregate::max('x')->into('col'),
            'd.', 'MAX(d.x)',
        ];

        yield 'unfiltered variance population' => [
            static fn (): AggregateDefinition => Aggregate::variance('x')->into('col'),
            'd.', '((1.0 * ((COUNT(d.x)) * (SUM(d.x * d.x)) - (SUM(d.x)) * (SUM(d.x)))) / NULLIF((COUNT(d.x)) * (COUNT(d.x)), 0))',
        ];

        yield 'unfiltered stddev sample' => [
            static fn (): AggregateDefinition => Aggregate::stddev('x', sample: true)->into('col'),
            'd.', 'SQRT(CASE WHEN ((1.0 * ((COUNT(d.x)) * (SUM(d.x * d.x)) - (SUM(d.x)) * (SUM(d.x)))) / NULLIF((COUNT(d.x)) * ((COUNT(d.x)) - 1), 0)) < 0 THEN 0 ELSE ((1.0 * ((COUNT(d.x)) * (SUM(d.x * d.x)) - (SUM(d.x)) * (SUM(d.x)))) / NULLIF((COUNT(d.x)) * ((COUNT(d.x)) - 1), 0)) END)',
        ];

        yield 'unfiltered bit_or' => [
            static fn (): AggregateDefinition => Aggregate::bitOr('flags')->into('col'),
            'd.', 'BIT_OR(d.flags)',
        ];

        yield 'unfiltered bit_and' => [
            static fn (): AggregateDefinition => Aggregate::bitAnd('flags')->into('col'),
            'd.', 'BIT_AND(d.flags)',
        ];

        yield 'unfiltered bit_xor' => [
            static fn (): AggregateDefinition => Aggregate::bitXor('flags')->into('col'),
            'd.', 'BIT_XOR(d.flags)',
        ];

        yield 'unfiltered weighted_avg' => [
            static fn (): AggregateDefinition => Aggregate::weightedAvg('v', 'w')->into('col'),
            'd.', '(1.0 * (SUM((d.w * d.v)))) / NULLIF((SUM(d.w)), 0)',
        ];

        yield 'unfiltered bool_or' => [
            static fn (): AggregateDefinition => Aggregate::boolOr('active')->into('col'),
            'd.', 'CASE WHEN (COUNT(d.active)) = 0 THEN NULL WHEN (SUM((CASE WHEN d.active THEN 1 ELSE 0 END))) > 0 THEN TRUE ELSE FALSE END',
        ];

        yield 'unfiltered geometric_mean' => [
            static fn (): AggregateDefinition => Aggregate::geometricMean('x')->into('col'),
            'd.', 'EXP(SUM(LN(CASE WHEN d.x > 0 THEN d.x ELSE NULL END)) / NULLIF(COUNT(CASE WHEN d.x > 0 THEN d.x ELSE NULL END), 0))',
        ];

        yield 'unfiltered harmonic_mean' => [
            static fn (): AggregateDefinition => Aggregate::harmonicMean('x')->into('col'),
            'd.', 'NULLIF(COUNT(NULLIF(d.x, 0)), 0) / NULLIF(SUM((1.0 / NULLIF(d.x, 0))), 0)',
        ];

        yield 'unfiltered median' => [
            static fn (): AggregateDefinition => Aggregate::median('x')->into('col'),
            'd.', 'PERCENTILE_CONT(0.5000000000) WITHIN GROUP (ORDER BY d.x)',
        ];

        yield 'unfiltered distinct_count' => [
            static fn (): AggregateDefinition => Aggregate::distinctCount('owner_id')->into('col'),
            'd.', 'COUNT(DISTINCT d.owner_id)',
        ];

        // Non-Identity sumBody transform: a TimesWeight companion Sum
        // (the weightRef-present branch).
        yield 'sum body times-weight transform' => [
            static fn (): AggregateDefinition => new AggregateDefinition(
                column: 'col',
                function: AggregateFunction::Sum,
                source: 'v',
                inclusive: true,
                sourceTransform: CompanionSourceTransform::TimesWeight,
                weight: 'w',
            ),
            'd.', 'COALESCE(SUM((d.w * d.v)), 0)',
        ];

        // Non-Identity sumBody transform with no weight (the weightRef =
        // null branch): a sum-of-squares companion Sum.
        yield 'sum body square transform no weight' => [
            static fn (): AggregateDefinition => new AggregateDefinition(
                column: 'col',
                function: AggregateFunction::Sum,
                source: 'x',
                inclusive: true,
                sourceTransform: CompanionSourceTransform::Square,
            ),
            'd.', 'COALESCE(SUM((d.x * d.x)), 0)',
        ];

        yield 'filtered sum equality' => [
            static fn (): AggregateDefinition => Aggregate::sum('x')->filter(['type' => 'fire'])->into('col'),
            'd.', "COALESCE(SUM(CASE WHEN d.type = 'fire' THEN d.x ELSE 0 END), 0)",
        ];

        yield 'filtered count star equality with null value' => [
            static fn (): AggregateDefinition => Aggregate::count()->filter(['parent_id' => null])->into('col'),
            'd.', 'COUNT(CASE WHEN d.parent_id IS NULL THEN 1 ELSE NULL END)',
        ];

        yield 'filtered count source notnull' => [
            static fn (): AggregateDefinition => Aggregate::count('x')->filterNotNull('active')->into('col'),
            'd.', 'COUNT(CASE WHEN d.active IS NOT NULL THEN d.x ELSE NULL END)',
        ];

        yield 'filtered avg equality' => [
            static fn (): AggregateDefinition => Aggregate::avg('x')->filter(['type' => 'fire'])->into('col'),
            'd.', "AVG(CASE WHEN d.type = 'fire' THEN d.x ELSE NULL END)",
        ];

        yield 'filtered min equality' => [
            static fn (): AggregateDefinition => Aggregate::min('x')->filter(['type' => 'fire'])->into('col'),
            'd.', "MIN(CASE WHEN d.type = 'fire' THEN d.x ELSE NULL END)",
        ];

        yield 'filtered max equality' => [
            static fn (): AggregateDefinition => Aggregate::max('x')->filter(['type' => 'fire'])->into('col'),
            'd.', "MAX(CASE WHEN d.type = 'fire' THEN d.x ELSE NULL END)",
        ];

        yield 'filtered variance population' => [
            static fn (): AggregateDefinition => Aggregate::variance('x')->filter(['type' => 'fire'])->into('col'),
            'd.', "((1.0 * ((COUNT(CASE WHEN d.type = 'fire' THEN d.x ELSE NULL END)) * (SUM(CASE WHEN d.type = 'fire' THEN d.x * d.x ELSE NULL END)) - (SUM(CASE WHEN d.type = 'fire' THEN d.x ELSE NULL END)) * (SUM(CASE WHEN d.type = 'fire' THEN d.x ELSE NULL END)))) / NULLIF((COUNT(CASE WHEN d.type = 'fire' THEN d.x ELSE NULL END)) * (COUNT(CASE WHEN d.type = 'fire' THEN d.x ELSE NULL END)), 0))",
        ];

        yield 'filtered stddev sample' => [
            static fn (): AggregateDefinition => Aggregate::stddev('x', sample: true)->filter(['type' => 'fire'])->into('col'),
            'd.', "SQRT(CASE WHEN ((1.0 * ((COUNT(CASE WHEN d.type = 'fire' THEN d.x ELSE NULL END)) * (SUM(CASE WHEN d.type = 'fire' THEN d.x * d.x ELSE NULL END)) - (SUM(CASE WHEN d.type = 'fire' THEN d.x ELSE NULL END)) * (SUM(CASE WHEN d.type = 'fire' THEN d.x ELSE NULL END)))) / NULLIF((COUNT(CASE WHEN d.type = 'fire' THEN d.x ELSE NULL END)) * ((COUNT(CASE WHEN d.type = 'fire' THEN d.x ELSE NULL END)) - 1), 0)) < 0 THEN 0 ELSE ((1.0 * ((COUNT(CASE WHEN d.type = 'fire' THEN d.x ELSE NULL END)) * (SUM(CASE WHEN d.type = 'fire' THEN d.x * d.x ELSE NULL END)) - (SUM(CASE WHEN d.type = 'fire' THEN d.x ELSE NULL END)) * (SUM(CASE WHEN d.type = 'fire' THEN d.x ELSE NULL END)))) / NULLIF((COUNT(CASE WHEN d.type = 'fire' THEN d.x ELSE NULL END)) * ((COUNT(CASE WHEN d.type = 'fire' THEN d.x ELSE NULL END)) - 1), 0)) END)",
        ];

        yield 'filtered bit_or equality' => [
            static fn (): AggregateDefinition => Aggregate::bitOr('flags')->filter(['type' => 'fire'])->into('col'),
            'd.', "BIT_OR(CASE WHEN d.type = 'fire' THEN d.flags ELSE NULL END)",
        ];

        yield 'filtered bit_and equality' => [
            static fn (): AggregateDefinition => Aggregate::bitAnd('flags')->filter(['type' => 'fire'])->into('col'),
            'd.', "BIT_AND(CASE WHEN d.type = 'fire' THEN d.flags ELSE NULL END)",
        ];

        yield 'filtered bit_xor equality' => [
            static fn (): AggregateDefinition => Aggregate::bitXor('flags')->filter(['type' => 'fire'])->into('col'),
            'd.', "BIT_XOR(CASE WHEN d.type = 'fire' THEN d.flags ELSE NULL END)",
        ];

        yield 'filtered weighted_avg equality' => [
            static fn (): AggregateDefinition => Aggregate::weightedAvg('v', 'w')->filter(['type' => 'fire'])->into('col'),
            'd.', "(1.0 * (SUM(CASE WHEN d.type = 'fire' THEN (d.w * d.v) ELSE 0 END))) / NULLIF((SUM(CASE WHEN d.type = 'fire' THEN d.w ELSE 0 END)), 0)",
        ];

        yield 'filtered bool_or equality' => [
            static fn (): AggregateDefinition => Aggregate::boolOr('active')->filter(['type' => 'fire'])->into('col'),
            'd.', "CASE WHEN (COUNT(CASE WHEN d.type = 'fire' AND d.active IS NOT NULL THEN 1 ELSE NULL END)) = 0 THEN NULL WHEN (SUM(CASE WHEN d.type = 'fire' THEN (CASE WHEN d.active THEN 1 ELSE 0 END) ELSE 0 END)) > 0 THEN TRUE ELSE FALSE END",
        ];

        yield 'filtered geometric_mean equality' => [
            static fn (): AggregateDefinition => Aggregate::geometricMean('x')->filter(['type' => 'fire'])->into('col'),
            'd.', "EXP(SUM(LN(CASE WHEN (d.type = 'fire') AND d.x > 0 THEN d.x ELSE NULL END)) / NULLIF(COUNT(CASE WHEN (d.type = 'fire') AND d.x > 0 THEN d.x ELSE NULL END), 0))",
        ];

        yield 'filtered harmonic_mean equality' => [
            static fn (): AggregateDefinition => Aggregate::harmonicMean('x')->filter(['type' => 'fire'])->into('col'),
            'd.', "NULLIF(COUNT(CASE WHEN (d.type = 'fire') AND d.x <> 0 THEN 1 ELSE NULL END), 0) / NULLIF(SUM(CASE WHEN (d.type = 'fire') AND d.x <> 0 THEN (1.0 / d.x) ELSE NULL END), 0)",
        ];

        yield 'filtered median equality' => [
            static fn (): AggregateDefinition => Aggregate::median('x')->filter(['type' => 'fire'])->into('col'),
            'd.', "PERCENTILE_CONT(0.5000000000) WITHIN GROUP (ORDER BY d.x) FILTER (WHERE d.type = 'fire')",
        ];

        yield 'filtered distinct_count equality' => [
            static fn (): AggregateDefinition => Aggregate::distinctCount('owner_id')->filter(['type' => 'fire'])->into('col'),
            'd.', "COUNT(DISTINCT CASE WHEN d.type = 'fire' THEN d.owner_id ELSE NULL END)",
        ];
    }

    /**
     * FilterPredicate::toFragment for every kind.
     *
     * @param  list<scalar|null>  $expectedBindings
     */
    #[DataProvider('filterPredicateCases')]
    public function test_filter_predicate_to_fragment(
        FilterPredicate $filter,
        string $qualifier,
        string $expectedSql,
        array $expectedBindings,
    ): void {
        $fragment = $filter->toFragment($qualifier);

        $this->assertSame($expectedSql, $fragment->sql);
        $this->assertSame($expectedBindings, $fragment->bindings);
    }

    /**
     * @return iterable<string, array{0: FilterPredicate, 1: string, 2: string, 3: list<scalar|null>}>
     */
    public static function filterPredicateCases(): iterable
    {
        yield 'equality with null and value' => [
            FilterPredicate::equality(['parent_id' => null, 'type' => 'fire']),
            'd.', 'd.parent_id IS NULL AND d.type = ?', ['fire'],
        ];

        yield 'not null' => [
            FilterPredicate::notNull('active'), 'd.', 'd.active IS NOT NULL', [],
        ];

        yield 'raw' => [
            FilterPredicate::raw('active = 1', ['active']), 'd.', 'active = 1', [],
        ];
    }

    public function test_collection_aggregate_without_connection_throws(): void
    {
        // A collection aggregate (DistinctCount) is backend-specific, so
        // an absent connection routes through requireConnection()'s throw.
        $this->expectException(AggregateConfigurationException::class);
        AggregateSqlFragments::aggregateExpression(
            Aggregate::distinctCount('owner_id')->into('col'),
            'd.',
        );
    }

    public function test_aggregate_expression_missing_source_throws(): void
    {
        // AVG requires a source column; a null-source definition routes
        // through requireSource()'s throw.
        $definition = new AggregateDefinition(
            column: 'col',
            function: AggregateFunction::Avg,
            source: null,
            inclusive: true,
        );

        $this->expectException(AggregateConfigurationException::class);
        AggregateSqlFragments::aggregateExpression($definition, 'd.', $this->sqliteConnection);
    }

    public function test_leaf_inline_weighted_avg_missing_weight_throws(): void
    {
        // A WeightedAvg definition with no weight column routes through
        // leafInlineWeightedAvg()'s throw via the leaf fast-path.
        $definition = new AggregateDefinition(
            column: 'col',
            function: AggregateFunction::WeightedAvg,
            source: 'v',
            inclusive: true,
            weight: null,
        );

        $this->expectException(AggregateConfigurationException::class);
        AggregateSqlFragments::wrapLeafFastPath(
            $definition,
            't.',
            'lft',
            'rgt',
            'JOIN_EXPR',
            null,
            $this->sqliteConnection,
        );
    }

    /**
     * hasRawFilter detection.
     */
    public function test_has_raw_filter(): void
    {
        $this->assertTrue(AggregateSqlFragments::hasRawFilter([
            Aggregate::sum('x')->into('a'),
            Aggregate::sum('y')->filterRaw('active = 1', ['active'])->into('b'),
        ]));

        $this->assertFalse(AggregateSqlFragments::hasRawFilter([
            Aggregate::sum('x')->into('a'),
            Aggregate::sum('y')->filter(['type' => 'fire'])->into('b'),
        ]));

        $this->assertFalse(AggregateSqlFragments::hasRawFilter([]));
    }

    /**
     * outerFromFragment in both simple and renamed-derived shapes.
     *
     * @param  array{from: string, outerLft: string, outerRgt: string, outerId: string, outerScope: array<string, string>, outerSoftDeleted: string|null}  $expected
     */
    #[DataProvider('outerFromCases')]
    public function test_outer_from_fragment(bool $rawFilterPresent, ?string $softDeletedColumn, array $expected): void
    {
        $result = AggregateSqlFragments::outerFromFragment(
            table: 'branches',
            lftCol: 'lft',
            rgtCol: 'rgt',
            scopeCols: ['menu_id'],
            rawFilterPresent: $rawFilterPresent,
            outerAlias: 'o',
            idCol: 'id',
            softDeletedColumn: $softDeletedColumn,
        );

        $this->assertSame($expected, $result);
    }

    /**
     * @return iterable<string, array{0: bool, 1: ?string, 2: array{from: string, outerLft: string, outerRgt: string, outerId: string, outerScope: array<string, string>, outerSoftDeleted: string|null}}>
     */
    public static function outerFromCases(): iterable
    {
        yield 'simple shape no raw filter no soft delete' => [
            false, null, [
                'from' => 'branches AS o',
                'outerLft' => 'o.lft',
                'outerRgt' => 'o.rgt',
                'outerId' => 'o.id',
                'outerScope' => ['menu_id' => 'o.menu_id'],
                'outerSoftDeleted' => null,
            ],
        ];

        yield 'simple shape with soft delete' => [
            false, 'deleted_at', [
                'from' => 'branches AS o',
                'outerLft' => 'o.lft',
                'outerRgt' => 'o.rgt',
                'outerId' => 'o.id',
                'outerScope' => ['menu_id' => 'o.menu_id'],
                'outerSoftDeleted' => 'o.deleted_at',
            ],
        ];

        yield 'renamed-derived shape with raw filter and soft delete' => [
            true, 'deleted_at', [
                'from' => '(SELECT id AS __nss_o_id, lft AS __nss_o_lft, rgt AS __nss_o_rgt, menu_id AS __nss_o_menu_id, deleted_at AS __nss_o_deleted_at FROM branches) AS o',
                'outerLft' => 'o.__nss_o_lft',
                'outerRgt' => 'o.__nss_o_rgt',
                'outerId' => 'o.__nss_o_id',
                'outerScope' => ['menu_id' => 'o.__nss_o_menu_id'],
                'outerSoftDeleted' => 'o.__nss_o_deleted_at',
            ],
        ];
    }

    /**
     * aggregateExpressionInJoinedContext: raw-filter inline, raw-filter
     * correlated fallback, and the plain (non-raw) delegation path.
     *
     * @param  Closure(): AggregateDefinition  $makeDefinition
     */
    #[DataProvider('joinedContextCases')]
    public function test_aggregate_expression_in_joined_context(
        Closure $makeDefinition,
        bool $rawFilterContext,
        string $expected,
    ): void {
        $fragment = AggregateSqlFragments::aggregateExpressionInJoinedContext(
            $makeDefinition(),
            innerQualifier: 'd.',
            outerAlias: 'o',
            table: 'branches',
            lftCol: 'lft',
            rgtCol: 'rgt',
            scopeCols: ['menu_id'],
            rawFilterContext: $rawFilterContext,
            connection: $this->sqliteConnection,
        );

        $this->assertSame($expected, $this->inline($fragment));
    }

    /**
     * @return iterable<string, array{0: Closure(): AggregateDefinition, 1: bool, 2: string}>
     */
    public static function joinedContextCases(): iterable
    {
        // Inline raw-filter expressions (rawFilterContext = true).
        yield 'inline raw sum' => [
            static fn (): AggregateDefinition => Aggregate::sum('x')->filterRaw('active = 1', ['active'])->into('col'),
            true, 'COALESCE(SUM(CASE WHEN active = 1 THEN d.x ELSE 0 END), 0)',
        ];

        yield 'inline raw count star' => [
            static fn (): AggregateDefinition => Aggregate::count()->filterRaw('active = 1', ['active'])->into('col'),
            true, 'COUNT(CASE WHEN active = 1 THEN 1 ELSE NULL END)',
        ];

        yield 'inline raw count source' => [
            static fn (): AggregateDefinition => Aggregate::count('x')->filterRaw('active = 1', ['active'])->into('col'),
            true, 'COUNT(CASE WHEN active = 1 THEN d.x ELSE NULL END)',
        ];

        yield 'inline raw avg' => [
            static fn (): AggregateDefinition => Aggregate::avg('x')->filterRaw('active = 1', ['active'])->into('col'),
            true, 'AVG(CASE WHEN active = 1 THEN d.x ELSE NULL END)',
        ];

        yield 'inline raw min' => [
            static fn (): AggregateDefinition => Aggregate::min('x')->filterRaw('active = 1', ['active'])->into('col'),
            true, 'MIN(CASE WHEN active = 1 THEN d.x ELSE NULL END)',
        ];

        yield 'inline raw max' => [
            static fn (): AggregateDefinition => Aggregate::max('x')->filterRaw('active = 1', ['active'])->into('col'),
            true, 'MAX(CASE WHEN active = 1 THEN d.x ELSE NULL END)',
        ];

        yield 'inline raw variance' => [
            static fn (): AggregateDefinition => Aggregate::variance('x')->filterRaw('active = 1', ['active'])->into('col'),
            true, '((1.0 * ((COUNT(CASE WHEN active = 1 THEN d.x ELSE NULL END)) * (SUM(CASE WHEN active = 1 THEN d.x * d.x ELSE NULL END)) - (SUM(CASE WHEN active = 1 THEN d.x ELSE NULL END)) * (SUM(CASE WHEN active = 1 THEN d.x ELSE NULL END)))) / NULLIF((COUNT(CASE WHEN active = 1 THEN d.x ELSE NULL END)) * (COUNT(CASE WHEN active = 1 THEN d.x ELSE NULL END)), 0))',
        ];

        yield 'inline raw stddev' => [
            static fn (): AggregateDefinition => Aggregate::stddev('x')->filterRaw('active = 1', ['active'])->into('col'),
            true, 'SQRT(CASE WHEN ((1.0 * ((COUNT(CASE WHEN active = 1 THEN d.x ELSE NULL END)) * (SUM(CASE WHEN active = 1 THEN d.x * d.x ELSE NULL END)) - (SUM(CASE WHEN active = 1 THEN d.x ELSE NULL END)) * (SUM(CASE WHEN active = 1 THEN d.x ELSE NULL END)))) / NULLIF((COUNT(CASE WHEN active = 1 THEN d.x ELSE NULL END)) * (COUNT(CASE WHEN active = 1 THEN d.x ELSE NULL END)), 0)) < 0 THEN 0 ELSE ((1.0 * ((COUNT(CASE WHEN active = 1 THEN d.x ELSE NULL END)) * (SUM(CASE WHEN active = 1 THEN d.x * d.x ELSE NULL END)) - (SUM(CASE WHEN active = 1 THEN d.x ELSE NULL END)) * (SUM(CASE WHEN active = 1 THEN d.x ELSE NULL END)))) / NULLIF((COUNT(CASE WHEN active = 1 THEN d.x ELSE NULL END)) * (COUNT(CASE WHEN active = 1 THEN d.x ELSE NULL END)), 0)) END)',
        ];

        yield 'inline raw bit_or' => [
            static fn (): AggregateDefinition => Aggregate::bitOr('flags')->filterRaw('active = 1', ['active'])->into('col'),
            true, 'BIT_OR(CASE WHEN active = 1 THEN d.flags ELSE NULL END)',
        ];

        yield 'inline raw weighted_avg' => [
            static fn (): AggregateDefinition => Aggregate::weightedAvg('v', 'w')->filterRaw('active = 1', ['active'])->into('col'),
            true, '(1.0 * (SUM(CASE WHEN active = 1 THEN (d.w * d.v) ELSE 0 END))) / NULLIF((SUM(CASE WHEN active = 1 THEN d.w ELSE 0 END)), 0)',
        ];

        yield 'inline raw distinct_count' => [
            static fn (): AggregateDefinition => Aggregate::distinctCount('owner_id')->filterRaw('active = 1', ['active'])->into('col'),
            true, 'COUNT(DISTINCT CASE WHEN active = 1 THEN d.owner_id ELSE NULL END)',
        ];

        // Correlated fallback (rawFilterContext = false).
        yield 'correlated raw sum inclusive' => [
            static fn (): AggregateDefinition => Aggregate::sum('x')->filterRaw('active = 1', ['active'])->into('col'),
            false, 'COALESCE((SELECT SUM(CASE WHEN active = 1 THEN nss_rf.x ELSE 0 END) FROM branches AS nss_rf WHERE nss_rf.lft >= o.lft AND nss_rf.lft <= o.rgt AND nss_rf.menu_id = o.menu_id), 0)',
        ];

        yield 'correlated raw count star exclusive' => [
            static fn (): AggregateDefinition => Aggregate::count()->exclusive()->filterRaw('active = 1', ['active'])->into('col'),
            false, 'COALESCE((SELECT COUNT(CASE WHEN active = 1 THEN 1 ELSE NULL END) FROM branches AS nss_rf WHERE nss_rf.lft > o.lft AND nss_rf.lft < o.rgt AND nss_rf.menu_id = o.menu_id), 0)',
        ];

        yield 'correlated raw count source' => [
            static fn (): AggregateDefinition => Aggregate::count('x')->filterRaw('active = 1', ['active'])->into('col'),
            false, 'COALESCE((SELECT COUNT(CASE WHEN active = 1 THEN nss_rf.x ELSE NULL END) FROM branches AS nss_rf WHERE nss_rf.lft >= o.lft AND nss_rf.lft <= o.rgt AND nss_rf.menu_id = o.menu_id), 0)',
        ];

        yield 'correlated raw avg' => [
            static fn (): AggregateDefinition => Aggregate::avg('x')->filterRaw('active = 1', ['active'])->into('col'),
            false, '(SELECT AVG(CASE WHEN active = 1 THEN nss_rf.x ELSE NULL END) FROM branches AS nss_rf WHERE nss_rf.lft >= o.lft AND nss_rf.lft <= o.rgt AND nss_rf.menu_id = o.menu_id)',
        ];

        yield 'correlated raw min' => [
            static fn (): AggregateDefinition => Aggregate::min('x')->filterRaw('active = 1', ['active'])->into('col'),
            false, '(SELECT MIN(CASE WHEN active = 1 THEN nss_rf.x ELSE NULL END) FROM branches AS nss_rf WHERE nss_rf.lft >= o.lft AND nss_rf.lft <= o.rgt AND nss_rf.menu_id = o.menu_id)',
        ];

        yield 'correlated raw max' => [
            static fn (): AggregateDefinition => Aggregate::max('x')->filterRaw('active = 1', ['active'])->into('col'),
            false, '(SELECT MAX(CASE WHEN active = 1 THEN nss_rf.x ELSE NULL END) FROM branches AS nss_rf WHERE nss_rf.lft >= o.lft AND nss_rf.lft <= o.rgt AND nss_rf.menu_id = o.menu_id)',
        ];

        yield 'correlated raw variance' => [
            static fn (): AggregateDefinition => Aggregate::variance('x')->filterRaw('active = 1', ['active'])->into('col'),
            false, '(SELECT ((1.0 * ((COUNT(CASE WHEN active = 1 THEN nss_rf.x ELSE NULL END)) * (SUM(CASE WHEN active = 1 THEN nss_rf.x * nss_rf.x ELSE NULL END)) - (SUM(CASE WHEN active = 1 THEN nss_rf.x ELSE NULL END)) * (SUM(CASE WHEN active = 1 THEN nss_rf.x ELSE NULL END)))) / NULLIF((COUNT(CASE WHEN active = 1 THEN nss_rf.x ELSE NULL END)) * (COUNT(CASE WHEN active = 1 THEN nss_rf.x ELSE NULL END)), 0)) FROM branches AS nss_rf WHERE nss_rf.lft >= o.lft AND nss_rf.lft <= o.rgt AND nss_rf.menu_id = o.menu_id)',
        ];

        yield 'correlated raw stddev' => [
            static fn (): AggregateDefinition => Aggregate::stddev('x')->filterRaw('active = 1', ['active'])->into('col'),
            false, '(SELECT SQRT(CASE WHEN ((1.0 * ((COUNT(CASE WHEN active = 1 THEN nss_rf.x ELSE NULL END)) * (SUM(CASE WHEN active = 1 THEN nss_rf.x * nss_rf.x ELSE NULL END)) - (SUM(CASE WHEN active = 1 THEN nss_rf.x ELSE NULL END)) * (SUM(CASE WHEN active = 1 THEN nss_rf.x ELSE NULL END)))) / NULLIF((COUNT(CASE WHEN active = 1 THEN nss_rf.x ELSE NULL END)) * (COUNT(CASE WHEN active = 1 THEN nss_rf.x ELSE NULL END)), 0)) < 0 THEN 0 ELSE ((1.0 * ((COUNT(CASE WHEN active = 1 THEN nss_rf.x ELSE NULL END)) * (SUM(CASE WHEN active = 1 THEN nss_rf.x * nss_rf.x ELSE NULL END)) - (SUM(CASE WHEN active = 1 THEN nss_rf.x ELSE NULL END)) * (SUM(CASE WHEN active = 1 THEN nss_rf.x ELSE NULL END)))) / NULLIF((COUNT(CASE WHEN active = 1 THEN nss_rf.x ELSE NULL END)) * (COUNT(CASE WHEN active = 1 THEN nss_rf.x ELSE NULL END)), 0)) END) FROM branches AS nss_rf WHERE nss_rf.lft >= o.lft AND nss_rf.lft <= o.rgt AND nss_rf.menu_id = o.menu_id)',
        ];

        yield 'correlated raw bit_xor' => [
            static fn (): AggregateDefinition => Aggregate::bitXor('flags')->filterRaw('active = 1', ['active'])->into('col'),
            false, '(SELECT BIT_XOR(CASE WHEN active = 1 THEN nss_rf.flags ELSE NULL END) FROM branches AS nss_rf WHERE nss_rf.lft >= o.lft AND nss_rf.lft <= o.rgt AND nss_rf.menu_id = o.menu_id)',
        ];

        yield 'correlated raw harmonic_mean' => [
            static fn (): AggregateDefinition => Aggregate::harmonicMean('x')->filterRaw('active = 1', ['active'])->into('col'),
            false, '(SELECT NULLIF(COUNT(CASE WHEN (active = 1) AND nss_rf.x <> 0 THEN 1 ELSE NULL END), 0) / NULLIF(SUM(CASE WHEN (active = 1) AND nss_rf.x <> 0 THEN (1.0 / nss_rf.x) ELSE NULL END), 0) FROM branches AS nss_rf WHERE nss_rf.lft >= o.lft AND nss_rf.lft <= o.rgt AND nss_rf.menu_id = o.menu_id)',
        ];

        yield 'correlated raw distinct_count' => [
            static fn (): AggregateDefinition => Aggregate::distinctCount('owner_id')->filterRaw('active = 1', ['active'])->into('col'),
            false, 'COALESCE((SELECT COUNT(DISTINCT CASE WHEN active = 1 THEN nss_rf.owner_id ELSE NULL END) FROM branches AS nss_rf WHERE nss_rf.lft >= o.lft AND nss_rf.lft <= o.rgt AND nss_rf.menu_id = o.menu_id), 0)',
        ];

        // Non-raw delegation path (equality filter or none) — exercises
        // the final `return aggregateExpression()` branch.
        yield 'non-raw equality delegates to aggregateExpression' => [
            static fn (): AggregateDefinition => Aggregate::sum('x')->filter(['type' => 'fire'])->into('col'),
            false, "COALESCE(SUM(CASE WHEN d.type = 'fire' THEN d.x ELSE 0 END), 0)",
        ];

        yield 'non-raw unfiltered delegates to aggregateExpression' => [
            static fn (): AggregateDefinition => Aggregate::sum('x')->into('col'),
            false, 'COALESCE(SUM(d.x), 0)',
        ];
    }

    /**
     * Multi-column scope: the correlated raw-filter fallback must emit
     * one `inner.col = outer.col` predicate per scope column. The loop
     * uses `$scopeClause .= ...`, so a `.= → =` mutation would drop all
     * but the last column — silently leaking aggregate rows across
     * tenant boundaries. Single-column fixtures can't observe this; the
     * data provider's other cases all use scopeCols=['menu_id'].
     */
    public function test_correlated_raw_filter_emits_every_scope_predicate(): void
    {
        $sql = AggregateSqlFragments::aggregateExpressionInJoinedContext(
            Aggregate::sum('x')->filterRaw('active = 1', ['active'])->into('col'),
            innerQualifier: 'd.',
            outerAlias: 'o',
            table: 'branches',
            lftCol: 'lft',
            rgtCol: 'rgt',
            scopeCols: ['tenant_id', 'site_id'],
            rawFilterContext: false,
            connection: $this->sqliteConnection,
        );

        $this->assertStringContainsString('nss_rf.tenant_id = o.tenant_id', $sql->sql);
        $this->assertStringContainsString('nss_rf.site_id = o.site_id', $sql->sql);
    }

    /**
     * wrapLeafFastPath drives leafInlineExpression + filteredLeafInline
     * across the function ladder, plus soft-delete wrapping.
     *
     * @param  Closure(): AggregateDefinition  $makeDefinition
     */
    #[DataProvider('leafFastPathCases')]
    public function test_wrap_leaf_fast_path(
        Closure $makeDefinition,
        ?string $softDeletedColumn,
        string $expected,
    ): void {
        $fragment = AggregateSqlFragments::wrapLeafFastPath(
            $makeDefinition(),
            't.',
            'lft',
            'rgt',
            'JOIN_EXPR',
            $softDeletedColumn,
            $this->sqliteConnection,
        );

        $this->assertSame($expected, $this->inline($fragment));
    }

    /**
     * @return iterable<string, array{0: Closure(): AggregateDefinition, 1: ?string, 2: string}>
     */
    public static function leafFastPathCases(): iterable
    {
        // Exclusive: empty-subtree element per function.
        yield 'leaf exclusive sum is zero' => [
            static fn (): AggregateDefinition => Aggregate::sum('x')->exclusive()->into('col'),
            null, 'CASE WHEN t.rgt = t.lft + 1 THEN 0 ELSE JOIN_EXPR END',
        ];

        yield 'leaf exclusive avg is null' => [
            static fn (): AggregateDefinition => Aggregate::avg('x')->exclusive()->into('col'),
            null, 'CASE WHEN t.rgt = t.lft + 1 THEN NULL ELSE JOIN_EXPR END',
        ];

        // Inclusive unfiltered ladder.
        yield 'leaf inclusive sum' => [
            static fn (): AggregateDefinition => Aggregate::sum('x')->into('col'),
            null, 'CASE WHEN t.rgt = t.lft + 1 THEN COALESCE(t.x, 0) ELSE JOIN_EXPR END',
        ];

        yield 'leaf inclusive count star' => [
            static fn (): AggregateDefinition => Aggregate::count()->into('col'),
            null, 'CASE WHEN t.rgt = t.lft + 1 THEN 1 ELSE JOIN_EXPR END',
        ];

        yield 'leaf inclusive count source' => [
            static fn (): AggregateDefinition => Aggregate::count('x')->into('col'),
            null, 'CASE WHEN t.rgt = t.lft + 1 THEN CASE WHEN t.x IS NULL THEN 0 ELSE 1 END ELSE JOIN_EXPR END',
        ];

        yield 'leaf inclusive avg' => [
            static fn (): AggregateDefinition => Aggregate::avg('x')->into('col'),
            null, 'CASE WHEN t.rgt = t.lft + 1 THEN t.x ELSE JOIN_EXPR END',
        ];

        yield 'leaf inclusive bit_or' => [
            static fn (): AggregateDefinition => Aggregate::bitOr('flags')->into('col'),
            null, 'CASE WHEN t.rgt = t.lft + 1 THEN t.flags ELSE JOIN_EXPR END',
        ];

        yield 'leaf inclusive variance population' => [
            static fn (): AggregateDefinition => Aggregate::variance('x')->into('col'),
            null, 'CASE WHEN t.rgt = t.lft + 1 THEN CASE WHEN t.x IS NULL THEN NULL ELSE 0 END ELSE JOIN_EXPR END',
        ];

        yield 'leaf inclusive variance sample' => [
            static fn (): AggregateDefinition => Aggregate::variance('x', sample: true)->into('col'),
            null, 'CASE WHEN t.rgt = t.lft + 1 THEN NULL ELSE JOIN_EXPR END',
        ];

        yield 'leaf inclusive weighted_avg' => [
            static fn (): AggregateDefinition => Aggregate::weightedAvg('v', 'w')->into('col'),
            null, 'CASE WHEN t.rgt = t.lft + 1 THEN (1.0 * (t.w * t.v)) / NULLIF(t.w, 0) ELSE JOIN_EXPR END',
        ];

        yield 'leaf inclusive bool_or' => [
            static fn (): AggregateDefinition => Aggregate::boolOr('active')->into('col'),
            null, 'CASE WHEN t.rgt = t.lft + 1 THEN CASE WHEN t.active IS NULL THEN NULL WHEN t.active THEN TRUE ELSE FALSE END ELSE JOIN_EXPR END',
        ];

        yield 'leaf inclusive geometric_mean' => [
            static fn (): AggregateDefinition => Aggregate::geometricMean('x')->into('col'),
            null, 'CASE WHEN t.rgt = t.lft + 1 THEN CASE WHEN t.x > 0 THEN t.x ELSE NULL END ELSE JOIN_EXPR END',
        ];

        yield 'leaf inclusive harmonic_mean' => [
            static fn (): AggregateDefinition => Aggregate::harmonicMean('x')->into('col'),
            null, 'CASE WHEN t.rgt = t.lft + 1 THEN CASE WHEN t.x <> 0 THEN t.x ELSE NULL END ELSE JOIN_EXPR END',
        ];

        yield 'leaf inclusive median' => [
            static fn (): AggregateDefinition => Aggregate::median('x')->into('col'),
            null, 'CASE WHEN t.rgt = t.lft + 1 THEN t.x ELSE JOIN_EXPR END',
        ];

        yield 'leaf inclusive distinct_count' => [
            static fn (): AggregateDefinition => Aggregate::distinctCount('owner_id')->into('col'),
            null, 'CASE WHEN t.rgt = t.lft + 1 THEN CASE WHEN t.owner_id IS NULL THEN 0 ELSE 1 END ELSE JOIN_EXPR END',
        ];

        // Inclusive + soft-delete wrapper. Sum's empty result is 0;
        // median's is NULL (exercises the tail arm of the emptyResult match).
        yield 'leaf inclusive sum with soft delete' => [
            static fn (): AggregateDefinition => Aggregate::sum('x')->into('col'),
            'deleted_at', 'CASE WHEN t.rgt = t.lft + 1 THEN (CASE WHEN t.deleted_at IS NULL THEN COALESCE(t.x, 0) ELSE 0 END) ELSE JOIN_EXPR END',
        ];

        yield 'leaf inclusive median with soft delete' => [
            static fn (): AggregateDefinition => Aggregate::median('x')->into('col'),
            'deleted_at', 'CASE WHEN t.rgt = t.lft + 1 THEN (CASE WHEN t.deleted_at IS NULL THEN t.x ELSE NULL END) ELSE JOIN_EXPR END',
        ];

        // Inclusive filtered ladder (filteredLeafInlineExpression).
        yield 'leaf filtered sum' => [
            static fn (): AggregateDefinition => Aggregate::sum('x')->filter(['type' => 'fire'])->into('col'),
            null, "CASE WHEN t.rgt = t.lft + 1 THEN COALESCE(CASE WHEN t.type = 'fire' THEN t.x ELSE 0 END, 0) ELSE JOIN_EXPR END",
        ];

        yield 'leaf filtered count star' => [
            static fn (): AggregateDefinition => Aggregate::count()->filter(['type' => 'fire'])->into('col'),
            null, "CASE WHEN t.rgt = t.lft + 1 THEN CASE WHEN t.type = 'fire' THEN 1 ELSE 0 END ELSE JOIN_EXPR END",
        ];

        yield 'leaf filtered count source' => [
            static fn (): AggregateDefinition => Aggregate::count('x')->filter(['type' => 'fire'])->into('col'),
            null, "CASE WHEN t.rgt = t.lft + 1 THEN CASE WHEN t.type = 'fire' AND t.x IS NOT NULL THEN 1 ELSE 0 END ELSE JOIN_EXPR END",
        ];

        yield 'leaf filtered avg' => [
            static fn (): AggregateDefinition => Aggregate::avg('x')->filter(['type' => 'fire'])->into('col'),
            null, "CASE WHEN t.rgt = t.lft + 1 THEN CASE WHEN t.type = 'fire' THEN t.x ELSE NULL END ELSE JOIN_EXPR END",
        ];

        yield 'leaf filtered variance population' => [
            static fn (): AggregateDefinition => Aggregate::variance('x')->filter(['type' => 'fire'])->into('col'),
            null, "CASE WHEN t.rgt = t.lft + 1 THEN CASE WHEN t.type = 'fire' AND t.x IS NOT NULL THEN 0 ELSE NULL END ELSE JOIN_EXPR END",
        ];

        yield 'leaf filtered variance sample' => [
            static fn (): AggregateDefinition => Aggregate::variance('x', sample: true)->filter(['type' => 'fire'])->into('col'),
            null, "CASE WHEN t.rgt = t.lft + 1 THEN CASE WHEN t.type = 'fire' AND t.x IS NOT NULL THEN NULL ELSE NULL END ELSE JOIN_EXPR END",
        ];

        yield 'leaf filtered weighted_avg' => [
            static fn (): AggregateDefinition => Aggregate::weightedAvg('v', 'w')->filter(['type' => 'fire'])->into('col'),
            null, "CASE WHEN t.rgt = t.lft + 1 THEN CASE WHEN t.type = 'fire' THEN (1.0 * (t.w * t.v)) / NULLIF(t.w, 0) ELSE NULL END ELSE JOIN_EXPR END",
        ];

        yield 'leaf filtered bool_or' => [
            static fn (): AggregateDefinition => Aggregate::boolOr('active')->filter(['type' => 'fire'])->into('col'),
            null, "CASE WHEN t.rgt = t.lft + 1 THEN CASE WHEN t.type = 'fire' THEN CASE WHEN t.active IS NULL THEN NULL WHEN t.active THEN TRUE ELSE FALSE END ELSE NULL END ELSE JOIN_EXPR END",
        ];

        yield 'leaf filtered geometric_mean' => [
            static fn (): AggregateDefinition => Aggregate::geometricMean('x')->filter(['type' => 'fire'])->into('col'),
            null, "CASE WHEN t.rgt = t.lft + 1 THEN CASE WHEN (t.type = 'fire') AND t.x > 0 THEN t.x ELSE NULL END ELSE JOIN_EXPR END",
        ];

        yield 'leaf filtered harmonic_mean' => [
            static fn (): AggregateDefinition => Aggregate::harmonicMean('x')->filter(['type' => 'fire'])->into('col'),
            null, "CASE WHEN t.rgt = t.lft + 1 THEN CASE WHEN (t.type = 'fire') AND t.x <> 0 THEN t.x ELSE NULL END ELSE JOIN_EXPR END",
        ];

        yield 'leaf filtered median' => [
            static fn (): AggregateDefinition => Aggregate::median('x')->filter(['type' => 'fire'])->into('col'),
            null, "CASE WHEN t.rgt = t.lft + 1 THEN CASE WHEN (t.type = 'fire') THEN t.x ELSE NULL END ELSE JOIN_EXPR END",
        ];

        yield 'leaf filtered distinct_count' => [
            static fn (): AggregateDefinition => Aggregate::distinctCount('owner_id')->filter(['type' => 'fire'])->into('col'),
            null, "CASE WHEN t.rgt = t.lft + 1 THEN CASE WHEN (t.type = 'fire') AND t.owner_id IS NOT NULL THEN 1 ELSE 0 END ELSE JOIN_EXPR END",
        ];
    }

    /**
     * Driver detection helpers.
     */
    public function test_driver_detection(): void
    {
        $this->assertFalse(AggregateSqlFragments::isMariaDb($this->sqliteConnection));
        $this->assertFalse(AggregateSqlFragments::isMySql($this->sqliteConnection));

        // A spoofed mysql-driver connection: real PDO server version is
        // SQLite, so isMariaDb is false and isMySql is true.
        $mysql = $this->fakeDriver('mysql');
        $this->assertFalse(AggregateSqlFragments::isMariaDb($mysql));
        $this->assertTrue(AggregateSqlFragments::isMySql($mysql));
    }

    public function test_driver_detection_non_concrete_connection(): void
    {
        // A ConnectionInterface that is not the concrete Connection class
        // can never be MySQL/MariaDB — both helpers short-circuit false.
        $stub = $this->createStub(ConnectionInterface::class);

        $this->assertFalse(AggregateSqlFragments::isMariaDb($stub));
        $this->assertFalse(AggregateSqlFragments::isMySql($stub));
    }

    public function test_is_maria_db_swallows_pdo_failure(): void
    {
        // When the driver is mysql but reading ATTR_SERVER_VERSION throws,
        // isMariaDb() swallows the error and reports false.
        $connection = new class extends SQLiteConnection
        {
            public function __construct() {}

            #[\Override]
            public function getDriverName(): string
            {
                return 'mysql';
            }

            #[\Override]
            public function getPdo(): \PDO
            {
                throw new \RuntimeException('PDO unavailable');
            }
        };

        $this->assertFalse(AggregateSqlFragments::isMariaDb($connection));
    }
}
