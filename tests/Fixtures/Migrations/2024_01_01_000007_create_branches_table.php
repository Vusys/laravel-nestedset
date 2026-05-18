<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('tickets')->default(0);
            // Stored as integer 0/1 (not boolean) so the raw-SQL filter
            // `active = 1` is portable across SQLite / MySQL / MariaDB / PG.
            // PG's boolean type rejects implicit boolean-vs-integer
            // comparisons; the integer storage sidesteps that.
            $table->unsignedTinyInteger('active')->default(1);

            // Cover `tickets` (the SUM source) so the inner aggregate
            // can do a covering index scan. Raw-filter watch columns
            // (here `active`) also go in the cover so the inline
            // STRAIGHT_JOIN'd CASE WHEN expression stays a covering
            // scan instead of falling back to non-covering index range
            // scans with primary-key row fetches per match.
            $table->nestedSet(cover: ['tickets', 'active']);

            // Inclusive (default) baseline for sanity comparison.
            $table->nestedSetAggregate('tickets_total');

            // Exclusive aggregates — descendants only, self excluded.
            $table->nestedSetAggregate('descendants_total');
            $table->nestedSetAggregate('descendants_count');
            $table->nestedSetAggregate('descendants_max', type: 'min_max');

            // Filtered with a raw SQL predicate — incremental maintenance
            // skips this column (no PHP evaluation possible). Recovered
            // via fixAggregates().
            $table->nestedSetAggregate('active_tickets_total');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
