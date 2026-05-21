<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('text_json_areas', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('tag')->nullable();
            $table->string('owner')->nullable();
            $table->boolean('published')->default(true);

            $table->nestedSet(cover: ['tag', 'owner']);

            $table->nestedSetAggregate('distinct_owners', type: 'distinct_count');
            $table->nestedSetAggregate('child_names', type: 'string_agg');
            $table->nestedSetAggregate('distinct_tags', type: 'string_agg');
            $table->nestedSetAggregate('descendant_ids', type: 'json');
            $table->nestedSetAggregate('descendant_summary', type: 'json');
            $table->nestedSetAggregate('name_lookup', type: 'json');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('text_json_areas');
    }
};
