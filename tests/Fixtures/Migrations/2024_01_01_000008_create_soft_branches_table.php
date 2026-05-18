<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors `branches` — exclusive and raw-filter SQL aggregates —
     * plus `softDeletes()`. Used by SoftBranch to exercise the
     * snapshot-semantics path through `RecomputeMaintenance`.
     */
    public function up(): void
    {
        Schema::create('soft_branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('tickets')->default(0);
            $table->unsignedTinyInteger('active')->default(1);

            $table->nestedSet(cover: ['tickets', 'active']);

            $table->nestedSetAggregate('tickets_total');
            $table->nestedSetAggregate('descendants_total');
            $table->nestedSetAggregate('descendants_count');
            $table->nestedSetAggregate('descendants_max', type: 'min_max');
            $table->nestedSetAggregate('active_tickets_total');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('soft_branches');
    }
};
