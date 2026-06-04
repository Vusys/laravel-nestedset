<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nullable_metric_areas', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            // Nullable source — exercises the MIN/MAX-ignore-NULL rule
            // in the create / restore lifecycle appliers (issue #178).
            $table->integer('score')->nullable();

            $table->nestedSet(cover: ['score']);

            $table->nestedSetAggregate('score_min', type: 'min_max');
            $table->nestedSetAggregate('score_max', type: 'min_max');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nullable_metric_areas');
    }
};
