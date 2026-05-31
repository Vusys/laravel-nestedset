<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lazy_areas', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('tickets')->default(0);

            $table->nestedSet(cover: ['tickets']);

            // Non-lazy eager control: standard sum_count column.
            $table->nestedSetAggregate('tickets_total');

            // Lazy SUM: nullable bigint + stamp companion.
            $table->nestedSetAggregate('lazy_tickets_total', lazy: true);

            // Lazy COUNT(*): same shape as the lazy sum.
            $table->nestedSetAggregate('lazy_tickets_count', lazy: true);

            // Lazy SUM with TTL — the model attribute carries `ttl: 60`,
            // the migration is unchanged because TTL is a read-time
            // policy, not a storage shape.
            $table->nestedSetAggregate('lazy_tickets_total_ttl', lazy: true);

            // Lazy EXCLUSIVE SUM — descendants-only rollup.
            $table->nestedSetAggregate('lazy_descendants_total', lazy: true);

            // Lazy listener SUM — same column shape as the SQL lazy
            // sums; the listener decides the per-node contribution.
            $table->nestedSetAggregate('lazy_listener_sum', lazy: true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lazy_areas');
    }
};
