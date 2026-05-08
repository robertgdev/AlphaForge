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
        Schema::create('alphaforge_renko', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_id')->constrained('alphaforge_markets')->cascadeOnDelete();
            $table->foreignId('timeframe_id')->constrained('alphaforge_timeframes')->cascadeOnDelete();
            $table->foreignId('renko_type_id')->constrained('alphaforge_renko_types')->cascadeOnDelete();
            $table->unsignedBigInteger('timestamp'); // Unix timestamp
            $table->decimal('open', 24, 12);
            $table->decimal('close', 24, 12);
            $table->enum('direction', ['up', 'down'])->default('up');

            // Unique constraint to prevent duplicate entries
            $table->unique(['market_id', 'timeframe_id', 'renko_type_id', 'timestamp']);

            // Indexes for efficient querying
            $table->index(['market_id', 'timeframe_id', 'renko_type_id', 'timestamp']);
            $table->index('timestamp');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alphaforge_renko');
    }
};
