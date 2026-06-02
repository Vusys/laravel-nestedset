<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stats_monsters', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type')->nullable();
            $table->decimal('score', 14, 4)->nullable();
            $table->boolean('active')->default(true);

            $table->nestedSet(cover: ['type', 'active']);

            // Variance display + Sum / Sum_sq / Count companions.
            $table->decimal('score_variance', 24, 12)->nullable();
            $table->decimal('score_variance__sum', 20, 4)->default(0);
            $table->decimal('score_variance__sum_sq', 30, 8)->default(0);
            $table->nestedSetAggregate('score_variance__count');

            // Stddev — shares the same companion shape as variance.
            $table->decimal('score_stddev', 24, 12)->nullable();
            $table->decimal('score_stddev__sum', 20, 4)->default(0);
            $table->decimal('score_stddev__sum_sq', 30, 8)->default(0);
            $table->nestedSetAggregate('score_stddev__count');

            // Geometric mean display + Sum_log / Count companions.
            $table->decimal('score_geomean', 24, 12)->nullable();
            $table->decimal('score_geomean__sum_log', 30, 10)->default(0);
            $table->nestedSetAggregate('score_geomean__count');

            // Harmonic mean display + Sum_recip / Count companions.
            $table->decimal('score_harmean', 24, 12)->nullable();
            $table->decimal('score_harmean__sum_recip', 30, 10)->default(0);
            $table->nestedSetAggregate('score_harmean__count');

            // Filtered Sum (`type = 'fire'` only) for the listener-filter tests.
            $table->nestedSetAggregate('fire_score_sum');

            // Filtered AVG via filterNotNull on `score`, with auto-promoted
            // sum + count companions. Display is a decimal because score is.
            $table->decimal('non_null_score_avg', 14, 4)->nullable();
            $table->decimal('non_null_score_avg__sum', 20, 4)->default(0);
            $table->nestedSetAggregate('non_null_score_avg__count');

            // Listener Min — nullable so empty / all-null subtrees stay
            // NULL. Used by the restore-path NULL-guard regression test.
            $table->decimal('score_min', 14, 4)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stats_monsters');
    }
};
