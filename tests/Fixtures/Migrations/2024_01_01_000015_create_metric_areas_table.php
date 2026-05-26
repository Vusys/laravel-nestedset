<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_areas', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('tickets')->default(0);

            $table->nestedSet(cover: ['tickets']);

            $table->nestedSetAggregate('tickets_variance', type: 'variance');
            $table->nestedSetAggregate('tickets_stddev', type: 'stddev');
            $table->nestedSetAggregate('tickets_variance_samp', type: 'variance');
            $table->nestedSetAggregate('tickets_stddev_samp', type: 'stddev');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_areas');
    }
};
