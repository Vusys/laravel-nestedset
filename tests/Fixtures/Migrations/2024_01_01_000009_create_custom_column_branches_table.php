<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Same aggregate surface as `branches`, but with custom names for
     * every nested-set structural column. Used by
     * `CustomColumnsBranch` to verify that no SQL builder hardcodes
     * `lft` / `rgt` / `depth` / `parent_id` strings.
     */
    public function up(): void
    {
        Schema::create('custom_column_branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('tickets')->default(0);
            $table->unsignedTinyInteger('active')->default(1);

            // Custom nested-set columns. The `nestedSet()` macro would
            // hardcode the default names; we build them by hand to
            // exercise model-side overrides of getLftName / getRgtName
            // / getDepthName / getParentIdName.
            $table->unsignedBigInteger('tree_lft')->default(0);
            $table->unsignedBigInteger('tree_rgt')->default(0);
            $table->unsignedInteger('tree_depth')->default(0);
            $table->unsignedBigInteger('tree_parent_id')->nullable();

            $table->index(['tree_lft', 'tree_rgt', 'tree_parent_id']);

            $table->bigInteger('tickets_total')->default(0);
            $table->bigInteger('descendants_total')->default(0);
            $table->bigInteger('descendants_count')->default(0);
            $table->bigInteger('descendants_max')->nullable();
            $table->bigInteger('active_tickets_total')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_column_branches');
    }
};
