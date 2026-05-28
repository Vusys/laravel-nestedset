<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scoped_areas', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedInteger('amount')->default(0);

            // `tenant_id` partitions the forest; the scope leads the index.
            $table->nestedSet(scope: 'tenant_id', cover: ['amount']);

            // SUM — delta-maintained on create/update/delete.
            $table->nestedSetAggregate('amount_total');
            // MIN — recompute-only; deleting the current minimum forces a
            // scoped subtree recompute.
            $table->nestedSetAggregate('amount_min', type: 'min_max');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scoped_areas');
    }
};
