<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitwise_areas', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('feature_bits')->default(0);

            $table->nestedSet(cover: ['feature_bits']);

            // Bitwise rollups — nullable bigint. Empty subtree reads as
            // NULL so callers can distinguish "no descendants" from
            // "every descendant had zero bits set".
            $table->nestedSetAggregate('features_or', type: 'bitwise');
            $table->nestedSetAggregate('features_and', type: 'bitwise');
            $table->nestedSetAggregate('features_xor', type: 'bitwise');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitwise_areas');
    }
};
