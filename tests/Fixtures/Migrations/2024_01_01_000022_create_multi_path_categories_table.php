<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('multi_path_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('display_name');
            $table->string('url_path', 1024)->nullable();
            $table->string('crumb_path', 1024)->nullable();
            $table->nestedSet();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('multi_path_categories');
    }
};
