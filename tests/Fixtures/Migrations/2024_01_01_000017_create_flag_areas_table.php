<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flag_areas', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('active')->default(false);
            $table->integer('value')->default(0);

            $table->nestedSet(cover: ['active']);

            $table->nestedSetAggregate('any_active', type: 'bool_or');
            $table->nestedSetAggregate('all_active', type: 'bool_and');
            // Filtered SUM over the boolean-cast `active` column — the
            // equality predicate `['active' => true]` is evaluated in PHP
            // against both the new and old attribute sets during delta
            // capture, exercising the cast-symmetry path. (Default macro
            // type is the sum column.)
            $table->nestedSetAggregate('active_value_total');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flag_areas');
    }
};
