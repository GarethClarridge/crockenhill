<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * H3 schema cleanup:
     * 1. Drop orphaned `sermon_processing_logs` table (superseded by `media_processing_logs`).
     * 2. Drop redundant `processing_id` column (and its FK/index) from `livestream_segments`;
     *    all reads use `media_processing_log_id`.
     * 3. Add DB-level ENUM constraint to `media_processing_logs.status` to match ProcessingStatus enum.
     */
    public function up(): void
    {
        Schema::dropIfExists('sermon_processing_logs');

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('livestream_segments', function (Blueprint $table) {
                $table->dropForeign('livestream_segments_processing_id_foreign');
                $table->dropIndex('livestream_segments_processing_id_index');
                $table->dropColumn('processing_id');
            });

            Schema::table('media_processing_logs', function (Blueprint $table) {
                $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'cancelled'])
                    ->default('pending')
                    ->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('media_processing_logs', function (Blueprint $table) {
                $table->string('status')->default('pending')->change();
            });

            Schema::table('livestream_segments', function (Blueprint $table) {
                $table->string('processing_id')->nullable()->after('id');
                $table->index('processing_id', 'livestream_segments_processing_id_index');
                $table->foreign('processing_id', 'livestream_segments_processing_id_foreign')
                    ->references('processing_id')
                    ->on('media_processing_logs')
                    ->onDelete('cascade');
            });
        }

        Schema::create('sermon_processing_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('processing_id', 36)->unique();
            $table->index('processing_id');
            $table->enum('source_type', ['audio', 'video', 'livestream']);
            $table->string('original_filename')->nullable();
            $table->string('stored_file_path')->nullable();
            $table->string('audio_file_path')->nullable();
            $table->string('transcript_path')->nullable();
            $table->json('ai_analysis')->nullable();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->index(['source_type', 'status']);
            $table->index(['status', 'created_at']);
            $table->string('current_step')->nullable();
            $table->text('error_message')->nullable();
            $table->json('source_metadata')->nullable();
            $table->unsignedInteger('sermon_id')->nullable();
            $table->foreign('sermon_id')->references('id')->on('sermons')->onDelete('set null');
            $table->timestamps();
        });
    }
};
