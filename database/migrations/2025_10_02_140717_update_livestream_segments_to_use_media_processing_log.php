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
        Schema::table('livestream_segments', function (Blueprint $table) {
            $table->renameColumn('processing_log_id', 'media_processing_log_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('livestream_segments', function (Blueprint $table) {
            $table->renameColumn('media_processing_log_id', 'processing_log_id');
        });
    }
};
