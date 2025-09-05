<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // For MySQL, we need to alter the enum to include new status values
        DB::statement("ALTER TABLE livestream_processing_logs MODIFY COLUMN status ENUM(
            'pending', 
            'rms_generation',
            'processing', 
            'segmentation',
            'segmenting', 
            'segmentation_complete',
            'extraction',
            'extraction_complete', 
            'transcription',
            'sermon_submitted', 
            'completed', 
            'failed'
        ) DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert back to the original enum values
        DB::statement("ALTER TABLE livestream_processing_logs MODIFY COLUMN status ENUM(
            'pending', 
            'processing', 
            'segmenting', 
            'extraction_complete', 
            'sermon_submitted', 
            'completed', 
            'failed'
        ) DEFAULT 'pending'");
    }
};
