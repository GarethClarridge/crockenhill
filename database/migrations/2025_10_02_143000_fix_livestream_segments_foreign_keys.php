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
            // Drop old foreign keys that reference livestream_processing_logs
            $table->dropForeign(['processing_id']);
            $table->dropForeign(['processing_log_id']);

            // Add foreign key to media_processing_logs.processing_id
            $table->foreign('processing_id')
                ->references('processing_id')
                ->on('media_processing_logs')
                ->onDelete('cascade');

            // Add foreign key to media_processing_logs.id
            $table->foreign('media_processing_log_id')
                ->references('id')
                ->on('media_processing_logs')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('livestream_segments', function (Blueprint $table) {
            // Drop new foreign keys
            $table->dropForeign(['processing_id']);
            $table->dropForeign(['media_processing_log_id']);

            // Restore old foreign keys (assuming old tables exist)
            $table->foreign('processing_id')
                ->references('processing_id')
                ->on('livestream_processing_logs')
                ->onDelete('cascade');

            $table->foreign('processing_log_id')
                ->references('id')
                ->on('livestream_processing_logs')
                ->onDelete('cascade');
        });
    }
};
