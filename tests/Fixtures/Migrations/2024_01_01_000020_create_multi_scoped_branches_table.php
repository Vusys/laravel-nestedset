<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('multi_scoped_branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');

            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('site_id');

            $table->unsignedInteger('tickets')->default(0);

            // No `cover:` column — MySQL/MariaDB cap composite-index
            // identifier names at 64 characters, and
            // `multi_scoped_branches_tenant_id_site_id_lft_rgt_parent_id_tickets_index`
            // is 70. The covering optimisation isn't load-bearing for
            // these correctness tests.
            $table->nestedSet(scope: ['tenant_id', 'site_id']);

            $table->nestedSetAggregate('tickets_total');
            $table->nestedSetAggregate('tickets_count');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('multi_scoped_branches');
    }
};
