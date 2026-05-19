<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table): void {
            // Custom primary key column (`tag_id`) rather than `id`.
            // Pins that every package path resolves the PK via
            // Model::getKeyName() instead of hardcoding the string 'id'.
            $table->bigIncrements('tag_id');
            $table->string('name');
            $table->unsignedInteger('tickets')->default(0);
            $table->nestedSet();
            $table->nestedSetAggregate('tickets_total');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
