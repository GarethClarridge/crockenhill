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
        Schema::create('livestream_processing_logs', function (Blueprint $table) {
            $table->id();
            $table->string('processing_id', 36)->unique();
            $table->string('original_filename', 255);
            $table->string('original_file_path', 500);
            $table->bigInteger('file_size')->unsigned();
            $table->string('file_format', 10)->nullable();
            $table->float('duration')->nullable();
            $table->enum('status', ['pending', 'processing', 'segmenting', 'extraction_complete', 'sermon_submitted', 'completed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->string('rms_log_path', 500)->nullable();
            $table->string('sermon_audio_path', 500)->nullable();
            $table->string('sermon_video_path', 500)->nullable();
            $table->float('sermon_start_time')->nullable();
            $table->float('sermon_end_time')->nullable();
            $table->string('sermon_processing_id', 36)->nullable();
            $table->integer('sermon_id')->unsigned()->nullable();
            $table->json('processing_metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('sermon_id')->references('id')->on('sermons')->onDelete('set null');
            $table->index('status');
            $table->index('processing_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('livestream_processing_logs');
    }
};
