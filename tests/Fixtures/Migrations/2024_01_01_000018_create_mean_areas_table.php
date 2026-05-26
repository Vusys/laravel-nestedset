<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mean_areas', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->decimal('value', 10, 4)->nullable();

            $table->nestedSet(cover: ['value']);

            $table->nestedSetAggregate('value_gmean', type: 'geometric_mean');
            $table->nestedSetAggregate('value_hmean', type: 'harmonic_mean');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mean_areas');
    }
};
