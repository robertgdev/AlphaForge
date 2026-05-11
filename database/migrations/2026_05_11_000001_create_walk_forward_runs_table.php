<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('walk_forward_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('optimization_run_id')->nullable()->constrained('optimization_runs')->nullOnDelete();

            $table->string('strategy_alias');
            $table->json('symbols');
            $table->string('timeframe', 10);
            $table->string('exchange', 50);
            $table->decimal('initial_capital', 20, 8);
            $table->string('stake_currency', 10);
            $table->json('commission_config')->nullable();

            $table->timestamp('is_start_date')->nullable();
            $table->timestamp('is_end_date')->nullable();
            $table->timestamp('oos_start_date')->nullable();
            $table->timestamp('oos_end_date')->nullable();

            $table->decimal('split_ratio', 5, 4)->default(0.7500);

            $table->string('optimization_method', 20)->default('random');
            $table->string('optimization_objective', 100)->nullable();
            $table->integer('top_n')->default(50);
            $table->json('parameter_ranges')->nullable();
            $table->unsignedInteger('total_combinations')->default(0);
            $table->unsignedInteger('completed_combinations')->default(0);

            $table->enum('status', ['pending', 'optimizing', 'forward_testing', 'completed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();

            $table->json('best_parameters')->nullable();
            $table->json('best_is_statistics')->nullable();
            $table->json('best_oos_statistics')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['strategy_alias', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('walk_forward_runs');
    }
};
