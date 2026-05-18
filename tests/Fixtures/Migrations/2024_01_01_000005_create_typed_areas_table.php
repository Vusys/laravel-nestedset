<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('typed_areas', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('tickets')->default(0);
            $table->string('type')->nullable();

            $table->nestedSet(cover: ['tickets', 'type']);

            $table->nestedSetAggregate('fire_tickets');
            $table->nestedSetAggregate('fire_count');
            $table->nestedSetAggregate('water_max', type: 'min_max');
            $table->nestedSetAggregate('has_tickets');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('typed_areas');
    }
};
