<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('multi_scoped_path_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('menu_id');
            $table->string('name');
            $table->string('url_path', 1024)->nullable();

            // No `cover:` column — the composite index name would exceed
            // MySQL/MariaDB's 64-character identifier cap.
            $table->nestedSet(scope: ['tenant_id', 'menu_id']);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('multi_scoped_path_items');
    }
};
