<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monsters', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type')->nullable();
            $table->unsignedInteger('base_power')->default(0);
            $table->unsignedInteger('level')->default(1);

            $table->nestedSet(cover: ['base_power', 'level', 'type']);

            $table->nestedSetAggregate('weighted_power');
            $table->nestedSetAggregate('fire_count');
            // Decimal column for float-listener tests: the listener returns
            // (base_power * level) / 2 — exercises the int|float path through
            // captureAggregateDeltas / DeltaMaintenance.
            $table->decimal('half_weighted_power', 14, 4)->default(0);
            // Nullable column for Min-listener tests: returns the
            // smallest level in the subtree. Exercises the listener
            // Min/Max recompute path on delete.
            $table->integer('weakest_level')->nullable();

            $table->timestamps();
            // Soft deletes so restore-path tests can exercise
            // applyAggregateOnRestore for listener aggregates.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monsters');
    }
};
