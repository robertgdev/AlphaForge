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
        Schema::create('stochastix_markets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exchange_id')->constrained('stochastix_exchanges')->cascadeOnDelete();
            $table->string('symbol'); // e.g., 'BTC/USDT'
            $table->string('base_currency', 20); // e.g., 'BTC'
            $table->string('quote_currency', 20); // e.g., 'USDT'
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['exchange_id', 'symbol']);
            $table->index(['exchange_id', 'active']);
            $table->index('symbol');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stochastix_markets');
    }
};
