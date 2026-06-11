<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guarded_nodes', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            // Deliberately NOT in the model's $fillable — exercises the
            // deep-copy force-fill path.
            $table->unsignedInteger('tickets')->default(0);
            $table->nestedSet();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guarded_nodes');
    }
};
