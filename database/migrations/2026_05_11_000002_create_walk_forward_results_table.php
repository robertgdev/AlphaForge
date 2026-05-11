<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('walk_forward_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('walk_forward_run_id')->constrained('walk_forward_runs')->cascadeOnDelete();

            $table->unsignedInteger('rank');
            $table->json('parameters');

            $table->decimal('is_final_capital', 20, 8)->nullable();
            $table->json('is_statistics')->nullable();
            $table->float('is_score')->nullable();

            $table->decimal('oos_final_capital', 20, 8)->nullable();
            $table->json('oos_statistics')->nullable();
            $table->float('oos_score')->nullable();

            $table->float('score_degradation')->nullable();

            $table->timestamps();

            $table->index(['walk_forward_run_id', 'rank']);
            $table->index('is_score');
            $table->index('oos_score');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('walk_forward_results');
    }
};
