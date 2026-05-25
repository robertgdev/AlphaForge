<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('backtest_runs', function (Blueprint $table) {
            $table->string('data_type', 20)->default('ohlcv')->after('end_date');
            $table->decimal('brick_size', 20, 8)->nullable()->after('data_type');
            $table->integer('atr_period')->nullable()->after('brick_size');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('backtest_runs', function (Blueprint $table) {
            $table->dropColumn(['data_type', 'brick_size', 'atr_period']);
        });
    }
};
