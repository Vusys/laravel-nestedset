<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pokemon', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type')->nullable();
            $table->unsignedInteger('base_power')->default(0);
            $table->unsignedInteger('level')->default(1);

            $table->nestedSet(cover: ['base_power', 'level', 'type']);

            $table->nestedSetAggregate('weighted_power');
            $table->nestedSetAggregate('fire_count');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pokemon');
    }
};
