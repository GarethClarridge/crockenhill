<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
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

            return;
        }

        Schema::table('livestream_processing_logs', function (Blueprint $table): void {
            $table->string('status')->default('pending')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE livestream_processing_logs MODIFY COLUMN status ENUM(
                'pending',
                'processing',
                'segmenting',
                'extraction_complete',
                'sermon_submitted',
                'completed',
                'failed'
            ) DEFAULT 'pending'");

            return;
        }

        Schema::table('livestream_processing_logs', function (Blueprint $table): void {
            $table->string('status')->default('pending')->change();
        });
    }
};
