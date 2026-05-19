<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uuid_tags', function (Blueprint $table): void {
            // String/UUID primary key. Pins that the package's parent_id
            // path accepts the matching uuid type, that the chunked
            // aggregate cursor works over an ordered UUID column, and
            // that mutation/repair carry the string identifier through.
            $table->uuid('id')->primary();
            $table->string('name');
            $table->unsignedInteger('tickets')->default(0);
            $table->nestedSet(parentIdType: 'uuid');
            $table->nestedSetAggregate('tickets_total');
            $table->nestedSetAggregate('name_length_total');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uuid_tags');
    }
};
