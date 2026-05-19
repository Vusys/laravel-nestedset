<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uuid_menu_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->uuid('menu_id');
            $table->nestedSet(parentIdType: 'uuid');
            $table->timestamps();

            $table->index(['menu_id', 'lft', 'rgt', 'parent_id']);
            $table->foreign('menu_id')->references('id')->on('uuid_menus')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uuid_menu_items');
    }
};
