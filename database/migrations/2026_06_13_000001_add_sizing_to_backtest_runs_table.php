<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backtest_runs', function (Blueprint $table) {
            $table->string('sizing_model')->default('percent_of_equity')->after('atr_period');
            $table->json('sizing_config')->nullable()->after('sizing_model');
        });
    }

    public function down(): void
    {
        Schema::table('backtest_runs', function (Blueprint $table) {
            $table->dropColumn(['sizing_config', 'sizing_model']);
        });
    }
};
