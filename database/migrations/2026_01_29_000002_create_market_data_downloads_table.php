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
        Schema::create('market_data_downloads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol', 20);
            $table->string('timeframe', 10);
            $table->string('exchange', 50);
            $table->timestamp('start_date');
            $table->timestamp('end_date');
            $table->enum('status', ['pending', 'downloading', 'processing', 'completed', 'failed'])->default('pending');
            $table->string('file_path')->nullable();
            $table->bigInteger('file_size')->nullable();
            $table->integer('bars_count')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['symbol', 'timeframe', 'exchange']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('market_data_downloads');
    }
};
