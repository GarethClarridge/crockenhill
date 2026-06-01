<?php

declare(strict_types=1);

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
        Schema::table('livestream_segments', function (Blueprint $table) {
            $table->index(['media_processing_log_id', 'start_time'], 'livestream_segments_log_start_time_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('livestream_segments', function (Blueprint $table) {
            $table->dropIndex('livestream_segments_log_start_time_index');
        });
    }
};
