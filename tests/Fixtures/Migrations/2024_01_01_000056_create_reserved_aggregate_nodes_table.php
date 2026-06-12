<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Vusys\NestedSet\Tests\Fixtures\Models\ReservedAggregateNode;

return new class extends Migration
{
    /**
     * Reserved-word structural columns (`left`/`right`/`order`) plus
     * aggregate columns, for
     * {@see ReservedAggregateNode}.
     * Exercises the aggregate maintenance + fresh-read SQL paths against
     * reserved identifiers.
     */
    public function up(): void
    {
        Schema::create('reserved_aggregate_nodes', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('weight')->default(0);

            $table->unsignedBigInteger('left')->default(0);
            $table->unsignedBigInteger('right')->default(0);
            $table->unsignedInteger('order')->default(0);
            $table->unsignedBigInteger('parent')->nullable();

            $table->index(['left', 'right', 'parent']);

            $table->bigInteger('weight_total')->default(0);
            $table->bigInteger('weight_sub')->default(0);
            $table->bigInteger('weight_max')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserved_aggregate_nodes');
    }
};
