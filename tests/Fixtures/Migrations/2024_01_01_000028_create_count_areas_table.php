<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('count_areas', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            // Nullable so a COUNT(source) row can transition between
            // contributing (non-null) and not (null) on update.
            $table->unsignedInteger('tickets')->nullable();
            $table->string('type')->nullable();

            $table->nestedSet(cover: ['tickets', 'type']);

            // SQL filtered COUNT(source) — the sourced (not COUNT(*))
            // variant that runs the Identity-transform Count delta path.
            $table->nestedSetAggregate('fire_ticket_count');
            // Listener COUNT — the only fixture exercising
            // operation: Count for a listener aggregate.
            $table->nestedSetAggregate('fire_node_count');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('count_areas');
    }
};
