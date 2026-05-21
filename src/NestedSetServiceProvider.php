<?php

declare(strict_types=1);

namespace Vusys\NestedSet;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\CompanionSpec;

final class NestedSetServiceProvider extends ServiceProvider
{
    /**
     * Type families accepted by the `nestedSetAggregate()` Blueprint
     * macro. Each maps to a specific column shape; see {@see boot()}.
     *
     * Types that name an aggregate function with a non-empty
     * {@see AggregateFunction::companionSet()} (today: `avg`) also
     * allocate the matching companion columns alongside the
     * user-facing column. The `dropNestedSetAggregate` companion macro
     * accepts the same `type` argument and drops the same set.
     */
    public const string AGGREGATE_TYPE_SUM_COUNT = 'sum_count';

    public const string AGGREGATE_TYPE_AVG = 'avg';

    public const string AGGREGATE_TYPE_MIN_MAX = 'min_max';

    public const string AGGREGATE_TYPE_DISTINCT_COUNT = 'distinct_count';

    public const string AGGREGATE_TYPE_STRING_AGG = 'string_agg';

    public const string AGGREGATE_TYPE_JSON = 'json';

    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nestedset.php', 'nestedset');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/nestedset.php' => config_path('nestedset.php'),
        ], 'nestedset-config');

        // Laravel rebinds macro closure scope to Blueprint, so self:: would resolve
        // to Blueprint. Capture the resolver as a static closure to preserve config access.
        $col = static function (string $key, string $default): string {
            $value = config("nestedset.columns.{$key}");

            return is_string($value) ? $value : $default;
        };

        Blueprint::macro('nestedSet', function (
            string|array $scope = [],
            string|array $cover = [],
            string|Closure $parentIdType = 'bigint',
        ) use ($col): void {
            /** @var Blueprint $this */
            $lft = $col('lft', Columns::LFT);
            $rgt = $col('rgt', Columns::RGT);
            $parentId = $col('parent_id', Columns::PARENT_ID);
            $depth = $col('depth', Columns::DEPTH);

            $this->unsignedBigInteger($lft)->default(0);
            $this->unsignedBigInteger($rgt)->default(0);
            NestedSetServiceProvider::addParentIdColumn($this, $parentId, $parentIdType);
            $this->unsignedInteger($depth)->default(0);

            $this->index(NestedSetServiceProvider::nestedSetIndexColumns(
                lft: $lft,
                rgt: $rgt,
                parentId: $parentId,
                scope: $scope,
                cover: $cover,
            ));
        });

        Blueprint::macro('dropNestedSet', function (
            string|array $scope = [],
            string|array $cover = [],
        ) use ($col): void {
            /** @var Blueprint $this */
            $lft = $col('lft', Columns::LFT);
            $rgt = $col('rgt', Columns::RGT);
            $parentId = $col('parent_id', Columns::PARENT_ID);
            $depth = $col('depth', Columns::DEPTH);

            $this->dropIndex(NestedSetServiceProvider::nestedSetIndexColumns(
                lft: $lft,
                rgt: $rgt,
                parentId: $parentId,
                scope: $scope,
                cover: $cover,
            ));
            $this->dropColumn([$lft, $rgt, $parentId, $depth]);
        });

        Blueprint::macro('nestedSetAggregate', function (
            string $column,
            string $type = NestedSetServiceProvider::AGGREGATE_TYPE_SUM_COUNT,
        ): void {
            /** @var Blueprint $this */
            NestedSetServiceProvider::addAggregateColumn($this, $column, $type);

            foreach (NestedSetServiceProvider::companionColumnsFor($column, $type) as $companionColumn) {
                NestedSetServiceProvider::addAggregateColumn(
                    $this,
                    $companionColumn,
                    NestedSetServiceProvider::AGGREGATE_TYPE_SUM_COUNT,
                );
            }
        });

        Blueprint::macro('dropNestedSetAggregate', function (
            string $column,
            string $type = NestedSetServiceProvider::AGGREGATE_TYPE_SUM_COUNT,
        ): void {
            /** @var Blueprint $this */
            $columns = [
                $column,
                ...NestedSetServiceProvider::companionColumnsFor($column, $type),
            ];

            $this->dropColumn($columns);
        });
    }

    /**
     * Emits a single aggregate storage column on $table. Internal helper
     * for the `nestedSetAggregate` Blueprint macro; also used to emit
     * companion columns (always shaped as `sum_count`).
     */
    public static function addAggregateColumn(Blueprint $table, string $column, string $type): void
    {
        if ($type === self::AGGREGATE_TYPE_SUM_COUNT) {
            // SUM / COUNT — non-null, default 0. Signed bigInteger
            // (not unsigned) so MariaDB strict mode doesn't reject
            // delta-subtraction expressions whose intermediate
            // type would be unsigned-minus-int. Range is still
            // 9.2 quintillion — ample for SUM over deep subtrees.
            $table->bigInteger($column)->default(0);

            return;
        }

        if ($type === self::AGGREGATE_TYPE_AVG) {
            // AVG — nullable decimal. Null indicates "no rows contributed"
            // (empty subtree under exclusive semantics, or after every
            // descendant has been deleted).
            $table->decimal($column, 12, 4)->nullable();

            return;
        }

        if ($type === self::AGGREGATE_TYPE_MIN_MAX) {
            // MIN / MAX — nullable signed big int. Signed because the
            // source column may legitimately hold negative values, and
            // empty subtrees yield NULL rather than 0.
            $table->bigInteger($column)->nullable();

            return;
        }

        if ($type === self::AGGREGATE_TYPE_DISTINCT_COUNT) {
            // DistinctCount — same shape as Count: non-null, default 0.
            $table->bigInteger($column)->default(0);

            return;
        }

        if ($type === self::AGGREGATE_TYPE_STRING_AGG) {
            // StringAgg — nullable text; empty subtree → NULL. Text rather
            // than string(...) because the natural upper bound is the
            // aggregate's `limit * avg_value_length`, not a fixed user
            // value.
            $table->text($column)->nullable();

            return;
        }

        if ($type === self::AGGREGATE_TYPE_JSON) {
            // JsonAgg / JsonObjectAgg — nullable. Backend-specific column
            // type: PG defaults to jsonb (faster reads, key normalisation);
            // MySQL/MariaDB use the JSON type; SQLite stores as text.
            // Laravel's $table->json($column)->nullable() handles this
            // dispatch correctly across all four backends.
            $table->json($column)->nullable();

            return;
        }

        throw new InvalidArgumentException(sprintf(
            'nestedSetAggregate: unknown type "%s". Supported: %s.',
            $type,
            implode(', ', self::knownAggregateTypes()),
        ));
    }

    /**
     * Returns the list of companion column names for an aggregate of
     * type $type targeting $column. Empty list when the type names
     * a function without companions (today: sum_count, min_max,
     * distinct_count, string_agg, json).
     *
     * Type → function mapping is loose by design — `sum_count` covers
     * Sum, Count, and any other delta-maintainable kind; `min_max`
     * covers Min and Max. AVG is the only type whose function
     * declares companions, so today this routine returns either two
     * companion names (for `avg`) or none.
     *
     * @return list<string>
     */
    public static function companionColumnsFor(string $column, string $type): array
    {
        $function = self::typeToFunction($type);
        if (! $function instanceof AggregateFunction) {
            return [];
        }

        return array_map(
            static fn (CompanionSpec $spec): string => $spec->columnFor($column),
            $function->companionSet(),
        );
    }

    /**
     * Map a `nestedSetAggregate` type string to the
     * {@see AggregateFunction} whose companion set it represents.
     * Returns null for types whose underlying functions have no
     * companions today (sum_count, min_max, distinct_count,
     * string_agg, json).
     */
    private static function typeToFunction(string $type): ?AggregateFunction
    {
        return match ($type) {
            self::AGGREGATE_TYPE_AVG => AggregateFunction::Avg,
            self::AGGREGATE_TYPE_SUM_COUNT,
            self::AGGREGATE_TYPE_MIN_MAX,
            self::AGGREGATE_TYPE_DISTINCT_COUNT,
            self::AGGREGATE_TYPE_STRING_AGG,
            self::AGGREGATE_TYPE_JSON => null,
            default => throw new InvalidArgumentException(sprintf(
                'nestedSetAggregate: unknown type "%s". Supported: %s.',
                $type,
                implode(', ', self::knownAggregateTypes()),
            )),
        };
    }

    /**
     * @return list<string>
     */
    private static function knownAggregateTypes(): array
    {
        return [
            self::AGGREGATE_TYPE_SUM_COUNT,
            self::AGGREGATE_TYPE_AVG,
            self::AGGREGATE_TYPE_MIN_MAX,
            self::AGGREGATE_TYPE_DISTINCT_COUNT,
            self::AGGREGATE_TYPE_STRING_AGG,
            self::AGGREGATE_TYPE_JSON,
        ];
    }

    /**
     * Compose the composite-index column list for the `nestedSet()`
     * Blueprint macro. Order matters — scope columns first (so a
     * scoped query lands in its own index slice), then the bounds,
     * then any covering columns appended to the leaves.
     *
     * For SUM / COUNT / AVG subtree subqueries (`fixAggregates` and
     * `withFreshAggregates`), the relevant query is
     * `WHERE inner.lft >= outer.lft AND inner.rgt <= outer.rgt`.
     * Including the source column at the tail of the composite turns
     * those subqueries into covering scans on every backend the
     * package supports — no heap visits per inner row.
     *
     * @param  string|array<int|string, string>  $scope
     * @param  string|array<int|string, string>  $cover
     * @return list<string>
     */
    public static function nestedSetIndexColumns(
        string $lft,
        string $rgt,
        string $parentId,
        string|array $scope = [],
        string|array $cover = [],
    ): array {
        return [
            ...self::toColumnList($scope),
            $lft,
            $rgt,
            $parentId,
            ...self::toColumnList($cover),
        ];
    }

    /**
     * Emits the `parent_id` column for the `nestedSet()` macro. Routed
     * through this helper rather than declared inline so callers can
     * pass `'uuid'`, `'ulid'`, `'string'`, or a closure for nanoid /
     * custom column shapes — match the model's `$keyType` so the FK
     * relationship between rows stays type-consistent.
     */
    public static function addParentIdColumn(Blueprint $table, string $column, string|Closure $type): void
    {
        if ($type instanceof Closure) {
            $type($table, $column);

            return;
        }

        match ($type) {
            'bigint', 'bigInteger', 'unsignedBigInteger' => $table->unsignedBigInteger($column)->nullable(),
            'integer', 'unsignedInteger' => $table->unsignedInteger($column)->nullable(),
            'uuid' => $table->uuid($column)->nullable(),
            'ulid' => $table->ulid($column)->nullable(),
            'string' => $table->string($column)->nullable(),
            default => throw new InvalidArgumentException(sprintf(
                'nestedSet: unsupported parentIdType "%s". Use "bigint", "uuid", "ulid", "string", or a Closure.',
                $type,
            )),
        };
    }

    /**
     * @param  string|array<int|string, string>  $columns
     * @return list<string>
     */
    private static function toColumnList(string|array $columns): array
    {
        if (is_string($columns)) {
            return $columns === '' ? [] : [$columns];
        }

        return array_values($columns);
    }
}
