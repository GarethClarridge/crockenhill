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
        Schema::table('livestream_processing_logs', function (Blueprint $table) {
            $table->enum('threshold_method', ['fixed', 'adaptive', 'fallback'])->default('fixed')->after('error_message');
            $table->decimal('adaptive_threshold', 5, 2)->nullable()->after('threshold_method');
            $table->json('rms_stats')->nullable()->after('adaptive_threshold');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('livestream_processing_logs', function (Blueprint $table) {
            $table->dropColumn(['threshold_method', 'adaptive_threshold', 'rms_stats']);
        });
    }
};
