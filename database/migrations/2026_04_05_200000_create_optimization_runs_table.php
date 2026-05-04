<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('optimization_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('strategy_alias');
            $table->json('symbols');
            $table->string('timeframe', 10);
            $table->string('exchange', 50);
            $table->decimal('initial_capital', 20, 8);
            $table->string('stake_currency', 10);
            $table->json('commission_config')->nullable();
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->json('parameter_ranges');
            $table->string('optimization_metric', 50)->default('sharpe_ratio');
            $table->unsignedInteger('total_combinations')->default(0);
            $table->unsignedInteger('completed_combinations')->default(0);
            $table->enum('status', ['pending', 'running', 'completed', 'failed'])->default('pending');
            $table->json('best_parameters')->nullable();
            $table->json('best_statistics')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['strategy_alias', 'status']);
            $table->index('created_at');
        });

        Schema::table('backtest_runs', function (Blueprint $table) {
            $table->foreignUuid('optimization_id')->nullable()->after('user_id')->constrained('optimization_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('backtest_runs', function (Blueprint $table) {
            $table->dropForeign(['optimization_id']);
            $table->dropColumn('optimization_id');
        });
        Schema::dropIfExists('optimization_runs');
    }
};
