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

            $table->nestedSetAggregate('tickets_total');
            $table->nestedSetAggregate('lazy_tickets_total', lazy: true);
            $table->nestedSetAggregate('lazy_tickets_count', lazy: true);

            // TTL is a read-time policy, not a storage shape — the
            // `ttl: 60` lives on the model attribute, the migration is
            // identical to any other lazy column.
            $table->nestedSetAggregate('lazy_tickets_total_ttl', lazy: true);

            $table->nestedSetAggregate('lazy_descendants_total', lazy: true);
            $table->nestedSetAggregate('lazy_listener_sum', lazy: true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lazy_areas');
    }
};
