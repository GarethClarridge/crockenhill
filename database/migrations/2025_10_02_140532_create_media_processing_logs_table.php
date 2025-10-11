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
        Schema::create('media_processing_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('processing_id')->unique();
            $table->enum('processing_type', ['audio', 'video', 'livestream']);
            $table->string('status');
            $table->string('current_step')->nullable();
            $table->text('error_message')->nullable();

            // File info
            $table->string('original_filename');
            $table->bigInteger('file_size')->nullable();
            $table->float('duration')->nullable();

            // File paths
            $table->string('source_file_path')->nullable();
            $table->string('audio_file_path')->nullable();
            $table->string('video_file_path')->nullable();
            $table->string('transcript_file_path')->nullable();

            // Livestream-specific
            $table->string('rms_log_path')->nullable();
            $table->float('sermon_start_time')->nullable();
            $table->float('sermon_end_time')->nullable();

            // Processing results
            $table->json('ai_analysis')->nullable();
            $table->json('processing_metadata')->nullable();

            // Adaptive threshold fields (for livestream processing)
            $table->string('threshold_method')->nullable();
            $table->float('adaptive_threshold')->nullable();
            $table->json('rms_stats')->nullable();

            // Relationships
            $table->unsignedInteger('sermon_id')->nullable();
            $table->foreign('sermon_id')->references('id')->on('sermons')->onDelete('cascade');

            // Timestamps
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('processing_type');
            $table->index('status');
            $table->index(['processing_type', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_processing_logs');
    }
};
