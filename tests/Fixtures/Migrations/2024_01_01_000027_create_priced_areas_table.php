<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('priced_areas', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->decimal('price', 10, 2)->default(0);

            $table->nestedSet(cover: ['price']);

            // MIN/MAX over a decimal source — the package's macro hardcodes
            // bigInteger for min_max display columns, so the storage column
            // is rolled here to match the source's decimal shape. Regression
            // fixture for the cheap-skip filterValue truncation bug.
            $table->decimal('price_min', 10, 2)->nullable();
            $table->decimal('price_max', 10, 2)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('priced_areas');
    }
};
