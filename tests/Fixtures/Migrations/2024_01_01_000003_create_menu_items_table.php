<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('menu_id');
            $table->nestedSet();
            $table->timestamps();

            // Scoped index: menu_id partitions the tree, so we need it leading the index.
            $table->index(['menu_id', 'lft', 'rgt', 'parent_id']);
            $table->foreign('menu_id')->references('id')->on('menus')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
