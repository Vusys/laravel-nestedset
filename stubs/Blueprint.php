<?php

namespace Illuminate\Database\Schema;

/**
 * PHPStan stub — teaches static analysis about macros registered by NestedSetServiceProvider.
 * Not loaded at runtime; only used during phpstan analyse.
 */
class Blueprint
{
    /**
     * @param  string|array<int|string, string>  $scope
     * @param  string|array<int|string, string>  $cover
     * @param  string|\Closure(Blueprint, string): void  $parentIdType
     */
    public function nestedSet(
        string|array $scope = [],
        string|array $cover = [],
        string|\Closure $parentIdType = 'bigint',
    ): void {}

    /**
     * @param  string|array<int|string, string>  $scope
     * @param  string|array<int|string, string>  $cover
     */
    public function dropNestedSet(
        string|array $scope = [],
        string|array $cover = [],
    ): void {}
}
