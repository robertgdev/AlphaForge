<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_signals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('exchange', 50);
            $table->string('symbol', 50);
            $table->enum('direction', ['LONG', 'SHORT']);
            $table->decimal('entry_price', 20, 8);
            $table->decimal('stop_loss', 20, 8);
            $table->decimal('take_profit', 20, 8);
            $table->boolean('trailing_stop_enabled')->default(false);
            $table->decimal('trailing_stop_percent', 12, 8)->nullable();
            $table->decimal('trailing_stop_high_water_mark', 20, 8)->nullable();
            $table->unsignedBigInteger('entry_timestamp');
            $table->enum('status', ['open', 'winner', 'loser'])->default('open');
            $table->decimal('exit_price', 20, 8)->nullable();
            $table->unsignedBigInteger('exit_timestamp')->nullable();
            $table->string('exit_reason', 50)->nullable();
            $table->decimal('profit_loss_pct', 12, 8)->nullable();
            $table->decimal('profit_loss_abs', 20, 8)->nullable();
            $table->timestamp('last_evaluated_at')->nullable();
            $table->string('timeframe', 10);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_evaluated_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_signals');
    }
};
