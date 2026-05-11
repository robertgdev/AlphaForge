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
        Schema::table('optimization_runs', function (Blueprint $table) {
            $table->string('optimization_method', 20)->default('grid')->after('parameter_ranges');
            $table->string('optimization_objective', 100)->nullable()->after('optimization_method');
            $table->integer('top_n')->default(50)->after('best_statistics');
            $table->json('top_results')->nullable()->after('top_n');
        });
    }

    public function down(): void
    {
        Schema::table('optimization_runs', function (Blueprint $table) {
            $table->dropColumn(['optimization_method', 'optimization_objective', 'top_n', 'top_results']);
        });
    }
};
