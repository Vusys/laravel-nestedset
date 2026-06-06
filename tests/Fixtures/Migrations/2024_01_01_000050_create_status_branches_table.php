<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('points')->default(0);
            // String column referenced by the raw filter
            // `status IN ('open', 'closed')` — pins that single-quoted
            // SQL literals round-trip cleanly through FragmentSplicer's
            // sentinel-replacement / parameter-binding stream.
            $table->string('status')->default('open');

            $table->nestedSet(cover: ['points', 'status']);

            $table->nestedSetAggregate('points_total');
            $table->nestedSetAggregate('open_or_closed_points_total');
            $table->nestedSetAggregate('open_or_closed_count');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_branches');
    }
};
