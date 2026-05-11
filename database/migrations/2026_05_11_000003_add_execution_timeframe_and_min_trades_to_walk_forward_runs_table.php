<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('walk_forward_runs', function (Blueprint $table) {
            $table->string('execution_timeframe', 10)->nullable()->after('timeframe');
            $table->unsignedInteger('min_trades_threshold')->nullable()->after('top_n');
        });
    }

    public function down(): void
    {
        Schema::table('walk_forward_runs', function (Blueprint $table) {
            $table->dropColumn(['execution_timeframe', 'min_trades_threshold']);
        });
    }
};
