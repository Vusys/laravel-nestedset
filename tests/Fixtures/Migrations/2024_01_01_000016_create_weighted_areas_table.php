<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weighted_areas', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->decimal('value', 10, 2)->nullable();
            $table->decimal('weight', 10, 2)->nullable();

            $table->nestedSet(cover: ['value', 'weight']);

            $table->nestedSetAggregate('value_wavg', type: 'weighted_avg');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weighted_areas');
    }
};
