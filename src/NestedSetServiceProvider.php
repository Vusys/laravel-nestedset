<?php

declare(strict_types=1);

namespace Vusys\NestedSet;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

final class NestedSetServiceProvider extends ServiceProvider
{
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

        Blueprint::macro('nestedSet', function () use ($col): void {
            /** @var Blueprint $this */
            $lft = $col('lft', Columns::LFT);
            $rgt = $col('rgt', Columns::RGT);
            $parentId = $col('parent_id', Columns::PARENT_ID);
            $depth = $col('depth', Columns::DEPTH);

            $this->unsignedBigInteger($lft)->default(0);
            $this->unsignedBigInteger($rgt)->default(0);
            $this->unsignedBigInteger($parentId)->nullable();
            $this->unsignedInteger($depth)->default(0);

            $this->index([$lft, $rgt, $parentId]);
        });

        Blueprint::macro('dropNestedSet', function () use ($col): void {
            /** @var Blueprint $this */
            $lft = $col('lft', Columns::LFT);
            $rgt = $col('rgt', Columns::RGT);
            $parentId = $col('parent_id', Columns::PARENT_ID);
            $depth = $col('depth', Columns::DEPTH);

            $this->dropIndex([$lft, $rgt, $parentId]);
            $this->dropColumn([$lft, $rgt, $parentId, $depth]);
        });
    }
}
