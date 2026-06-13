<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Vusys\NestedSet\Tests\Fixtures\Models\ScopedPathNode;

return new class extends Migration
{
    /**
     * Two-column scope + raw `attribute:` materialised-path source, for
     * {@see ScopedPathNode}. Drives
     * the subtree-path-rewrite UPDATE's multi-column scope binding and its
     * multibyte SUBSTRING offset.
     */
    public function up(): void
    {
        Schema::create('scoped_path_nodes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('tenant_id');
            $table->unsignedInteger('menu_id');
            $table->string('name');
            $table->string('path', 1024)->nullable();
            $table->nestedSet(scope: ['tenant_id', 'menu_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scoped_path_nodes');
    }
};
