<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('tickets')->default(0);

            // `cover: ['tickets']` appends the source column to the
            // composite index leaves so subtree-aggregate subqueries
            // can do index-only scans.
            $table->nestedSet(cover: ['tickets']);

            // SUM / COUNT — non-null, default 0
            $table->nestedSetAggregate('tickets_total');
            $table->nestedSetAggregate('tickets_count_all');

            // AVG — nullable decimal; null on empty subtree. The macro
            // also allocates the `tickets_avg__sum` and
            // `tickets_avg__count` companion columns the maintenance
            // machinery writes alongside the display value.
            $table->nestedSetAggregate('tickets_avg', type: 'avg');

            // MIN / MAX — nullable; empty subtree yields NULL
            $table->nestedSetAggregate('tickets_min', type: 'min_max');
            $table->nestedSetAggregate('tickets_max', type: 'min_max');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('areas');
    }
};
