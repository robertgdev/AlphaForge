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
        Schema::create('alphaforge_timeframes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // e.g., '1m', '5m', '1h', '1d'
            $table->unsignedInteger('minutes'); // Duration in minutes
            $table->timestamps();

            $table->index('name');
            $table->index('minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alphaforge_timeframes');
    }
};
