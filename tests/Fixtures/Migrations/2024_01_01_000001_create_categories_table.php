<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('lft')->default(0);
            $table->unsignedBigInteger('rgt')->default(0);
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedInteger('depth')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['lft', 'rgt', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
