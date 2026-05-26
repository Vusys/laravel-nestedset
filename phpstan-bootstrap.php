<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;

// Register Blueprint macros so Larastan's MacroMethodsClassReflectionExtension
// can resolve them via the runtime $macros static property.
Blueprint::macro('nestedSet', function (string|array $scope = [], string|array $cover = [], string|Closure $parentIdType = 'bigint'): void {});
Blueprint::macro('dropNestedSet', function (string|array $scope = [], string|array $cover = []): void {});
Blueprint::macro('nestedSetAggregate', function (string $column, string $type = 'sum_count'): void {});
Blueprint::macro('dropNestedSetAggregate', function (string $column, string $type = 'sum_count'): void {});
