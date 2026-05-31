<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('top_k_areas', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            // `revenue` is the ranking column; nullable so we can verify
            // null-`by` rows are excluded from the top-K result.
            $table->integer('revenue')->nullable();
            // `category` is used by the filtered-TopK test.
            $table->string('category')->nullable();

            $table->nestedSet();

            $table->nestedSetAggregate('top_revenue_ids', type: 'top_k');
            $table->nestedSetAggregate('top_active_ids', type: 'top_k');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('top_k_areas');
    }
};
