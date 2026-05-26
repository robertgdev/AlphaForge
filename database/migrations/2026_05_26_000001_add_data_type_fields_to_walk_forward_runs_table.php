<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('walk_forward_runs', function (Blueprint $table) {
            $table->string('data_type')->default('ohlcv')->after('execution_timeframe');
            $table->decimal('brick_size', 20, 8)->nullable()->after('data_type');
            $table->integer('atr_period')->nullable()->after('brick_size');
        });
    }

    public function down(): void
    {
        Schema::table('walk_forward_runs', function (Blueprint $table) {
            $table->dropColumn(['data_type', 'brick_size', 'atr_period']);
        });
    }
};
