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
            $table->string('execution_timeframe', 10)->nullable()->after('timeframe');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('backtest_runs', function (Blueprint $table) {
            $table->dropColumn('execution_timeframe');
        });
    }
};
