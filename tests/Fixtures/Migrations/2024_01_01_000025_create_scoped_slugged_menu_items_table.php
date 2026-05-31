<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scoped_slugged_menu_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('menu_id');
            $table->string('name');
            $table->string('url_path', 1024)->nullable();
            $table->nestedSet(scope: 'menu_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scoped_slugged_menu_items');
    }
};
