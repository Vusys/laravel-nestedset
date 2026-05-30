<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monsters', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type')->nullable();
            $table->unsignedInteger('base_power')->default(0);
            $table->unsignedInteger('level')->default(1);

            $table->nestedSet(cover: ['base_power', 'level', 'type']);

            $table->nestedSetAggregate('weighted_power');
            $table->nestedSetAggregate('fire_count');
            // Decimal column for float-listener tests: the listener returns
            // (base_power * level) / 2 — exercises the int|float path through
            // captureAggregateDeltas / DeltaMaintenance.
            $table->decimal('half_weighted_power', 14, 4)->default(0);
            // Nullable column for Min-listener tests: returns the
            // smallest level in the subtree. Exercises the listener
            // Min/Max recompute path on delete.
            $table->integer('weakest_level')->nullable();
            // Companion Max-listener column — required to exercise the
            // `$def->operation === AggregateFunction::Max` arm of the
            // chain-inclusion check in HasNestedSetAggregates. Without
            // it the Min arm covers half the logic and the Max half
            // escapes.
            $table->integer('strongest_level')->nullable();

            // Listener AVG: display column + auto-promoted Sum + Count
            // companions. Decimal display column to preserve fractional
            // averages; integer sum/count companions because the
            // WeightedPowerListener returns integer contributions.
            $table->decimal('weighted_avg', 14, 4)->nullable();
            $table->nestedSetAggregate('weighted_avg__sum');
            $table->nestedSetAggregate('weighted_avg__count');

            // Exclusive listener column — counts only DESCENDANTS that
            // match the FireCountListener predicate (the node itself
            // never contributes to its own value). Forces the strict-
            // bounds branch (`innerLft > outerLft && innerRgt < outerRgt`)
            // in ListenerMaintenance and HasNestedSetAggregates; until
            // this column existed no listener fixture was exclusive, so
            // the `>` checks were equivalent to `>=` against the suite.
            $table->integer('descendant_fire_count')->default(0);

            $table->timestamps();
            // Soft deletes so restore-path tests can exercise
            // applyAggregateOnRestore for listener aggregates.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monsters');
    }
};
