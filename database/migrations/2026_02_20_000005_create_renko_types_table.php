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
        Schema::create('stochastix_renko_types', function (Blueprint $table) {
            $table->id();
            $table->string('method'); // e.g., 'atr', 'fixed', 'percentage'
            $table->decimal('brick_size', 24, 12);
            $table->json('params')->nullable(); // Additional parameters as JSON
            $table->timestamps();

            $table->index('method');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stochastix_renko_types');
    }
};
