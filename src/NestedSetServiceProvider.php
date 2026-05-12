<?php

declare(strict_types=1);

namespace Vusys\NestedSet;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

final class NestedSetServiceProvider extends ServiceProvider
{
    /**
     * Type families accepted by the `nestedSetAggregate()` Blueprint
     * macro. Each maps to a specific column shape; see {@see boot()}.
     */
    public const string AGGREGATE_TYPE_SUM_COUNT = 'sum_count';

    public const string AGGREGATE_TYPE_AVG = 'avg';

    public const string AGGREGATE_TYPE_MIN_MAX = 'min_max';

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
        ) use ($col): void {
            /** @var Blueprint $this */
            $lft = $col('lft', Columns::LFT);
            $rgt = $col('rgt', Columns::RGT);
            $parentId = $col('parent_id', Columns::PARENT_ID);
            $depth = $col('depth', Columns::DEPTH);

            $this->unsignedBigInteger($lft)->default(0);
            $this->unsignedBigInteger($rgt)->default(0);
            $this->unsignedBigInteger($parentId)->nullable();
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
            if ($type === NestedSetServiceProvider::AGGREGATE_TYPE_SUM_COUNT) {
                // SUM / COUNT — non-null, default 0. Signed bigInteger
                // (not unsigned) so MariaDB strict mode doesn't reject
                // delta-subtraction expressions whose intermediate
                // type would be unsigned-minus-int. Range is still
                // 9.2 quintillion — ample for SUM over deep subtrees.
                $this->bigInteger($column)->default(0);

                return;
            }

            if ($type === NestedSetServiceProvider::AGGREGATE_TYPE_AVG) {
                // AVG — nullable decimal. Null indicates "no rows contributed"
                // (empty subtree under exclusive semantics, or after every
                // descendant has been deleted).
                $this->decimal($column, 12, 4)->nullable();

                return;
            }

            if ($type === NestedSetServiceProvider::AGGREGATE_TYPE_MIN_MAX) {
                // MIN / MAX — nullable signed big int. Signed because the
                // source column may legitimately hold negative values, and
                // empty subtrees yield NULL rather than 0.
                $this->bigInteger($column)->nullable();

                return;
            }

            throw new InvalidArgumentException(sprintf(
                'nestedSetAggregate: unknown type "%s". Use "%s", "%s", or "%s".',
                $type,
                NestedSetServiceProvider::AGGREGATE_TYPE_SUM_COUNT,
                NestedSetServiceProvider::AGGREGATE_TYPE_AVG,
                NestedSetServiceProvider::AGGREGATE_TYPE_MIN_MAX,
            ));
        });

        Blueprint::macro('dropNestedSetAggregate', function (string $column): void {
            /** @var Blueprint $this */
            $this->dropColumn($column);
        });
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
