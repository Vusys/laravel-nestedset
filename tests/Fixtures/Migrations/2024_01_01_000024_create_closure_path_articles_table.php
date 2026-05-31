<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('closure_path_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('breadcrumb_path', 2048)->nullable();
            $table->nestedSet();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('closure_path_articles');
    }
};
