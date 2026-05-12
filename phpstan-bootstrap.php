<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;

// Register Blueprint macros so Larastan's MacroMethodsClassReflectionExtension
// can resolve them via the runtime $macros static property.
Blueprint::macro('nestedSet', function (): void {});
Blueprint::macro('dropNestedSet', function (): void {});
Blueprint::macro('nestedSetAggregate', function (string $column, string $type = 'sum_count'): void {});
Blueprint::macro('dropNestedSetAggregate', function (string $column): void {});
