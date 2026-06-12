<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Vusys\NestedSet\Tests\Fixtures\Models\ReservedColumnNode;

return new class extends Migration
{
    /**
     * Structural columns named after SQL reserved words (`left`, `right`,
     * `order`) so {@see ReservedColumnNode}
     * proves every raw-SQL mutation/repair path grammar-quotes the column
     * names. Built by hand (the nestedSet() macro uses the default names).
     */
    public function up(): void
    {
        Schema::create('reserved_column_nodes', function (Blueprint $table): void {
            $table->id();
            $table->string('name');

            $table->unsignedBigInteger('left')->default(0);
            $table->unsignedBigInteger('right')->default(0);
            $table->unsignedInteger('order')->default(0);
            $table->unsignedBigInteger('parent')->nullable();

            $table->index(['left', 'right', 'parent']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserved_column_nodes');
    }
};
