<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Minimal soft-delete + aggregate table, but with the soft-delete
     * column renamed to `archived_at`. Used by `ArchivedBranch` to
     * exercise dynamic resolution of `getDeletedAtColumn()` across
     * every path that previously hardcoded `'deleted_at'`.
     */
    public function up(): void
    {
        Schema::create('archived_branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('tickets')->default(0);

            $table->nestedSet(cover: ['tickets']);

            $table->nestedSetAggregate('tickets_total');

            $table->timestamps();
            $table->timestamp('archived_at')->nullable();
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archived_branches');
    }
};
