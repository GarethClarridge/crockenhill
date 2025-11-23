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
        // Add visual analysis columns to media_processing_logs
        Schema::table('media_processing_logs', function (Blueprint $table) {
            $table->json('visual_samples')->nullable()->after('rms_stats')
                ->comment('Visual frame analysis samples with timestamps and classifications');
            $table->json('song_clusters')->nullable()->after('visual_samples')
                ->comment('Clustered song periods identified from visual analysis');
            $table->integer('visual_sample_count')->nullable()->after('song_clusters')
                ->comment('Total number of visual samples analyzed');
            $table->float('visual_processing_time')->nullable()->after('visual_sample_count')
                ->comment('Time taken for visual analysis in seconds');
        });

        // Add visual metadata columns to livestream_segments
        Schema::table('livestream_segments', function (Blueprint $table) {
            $table->float('visual_confidence')->nullable()->after('peak_rms')
                ->comment('Confidence score from visual classification (0-1)');
            $table->integer('visual_sample_count')->nullable()->after('visual_confidence')
                ->comment('Number of visual samples in this segment');
            $table->string('calibration_method')->nullable()->after('visual_sample_count')
                ->comment('Method used for threshold calibration: per_song_visual, adaptive, fixed, fallback');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_processing_logs', function (Blueprint $table) {
            $table->dropColumn([
                'visual_samples',
                'song_clusters',
                'visual_sample_count',
                'visual_processing_time',
            ]);
        });

        Schema::table('livestream_segments', function (Blueprint $table) {
            $table->dropColumn([
                'visual_confidence',
                'visual_sample_count',
                'calibration_method',
            ]);
        });
    }
};
