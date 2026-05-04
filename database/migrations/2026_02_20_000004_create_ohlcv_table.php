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
        Schema::create('stochastix_ohlcv', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_id')->constrained('stochastix_markets')->cascadeOnDelete();
            $table->foreignId('timeframe_id')->constrained('stochastix_timeframes')->cascadeOnDelete();
            $table->unsignedBigInteger('timestamp'); // Unix timestamp
            $table->decimal('open', 24, 12);
            $table->decimal('high', 24, 12);
            $table->decimal('low', 24, 12);
            $table->decimal('close', 24, 12);
            $table->decimal('volume', 24, 12);

            // Unique constraint to prevent duplicate entries for same market/timeframe/timestamp
            $table->unique(['market_id', 'timeframe_id', 'timestamp']);

            // Indexes for efficient querying
            $table->index(['market_id', 'timeframe_id', 'timestamp']);
            $table->index('timestamp');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stochastix_ohlcv');
    }
};
