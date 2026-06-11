<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bool_filter_areas', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('tickets')->default(0);
            $table->boolean('active')->default(false);
            $table->nestedSet();
            $table->nestedSetAggregate('active_tickets');
            $table->nestedSetAggregate('active_count');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bool_filter_areas');
    }
};
