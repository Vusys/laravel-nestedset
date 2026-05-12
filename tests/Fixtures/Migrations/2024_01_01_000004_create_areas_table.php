<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('tickets')->default(0);

            $table->nestedSet();

            // SUM / COUNT: non-null, default 0
            $table->unsignedBigInteger('tickets_total')->default(0);
            $table->unsignedBigInteger('tickets_count_all')->default(0);

            // AVG: nullable decimal — divide-by-zero on empty subtree yields NULL
            $table->decimal('tickets_avg', 12, 4)->nullable();

            // MIN / MAX: nullable; empty subtree has no extremum
            $table->integer('tickets_min')->nullable();
            $table->integer('tickets_max')->nullable();

            // Internal AVG companions — written by Phase E maintenance.
            // Phase B never reads these from a fresh query (AVG is computed
            // directly from the source column) but they exist now so the
            // migration is forward-compatible.
            $table->unsignedBigInteger('tickets_avg__sum')->default(0);
            $table->unsignedBigInteger('tickets_avg__count')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('areas');
    }
};
