<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('livestream_processing_logs')) {
            $legacyRowCount = DB::table('livestream_processing_logs')->count();

            if ($legacyRowCount > 0) {
                // No model or service references this table; rows are orphaned legacy processing
                // logs. Truncate before dropping so the migration is self-healing on deploy.
                Log::warning(
                    "Truncating {$legacyRowCount} orphaned row(s) from legacy livestream_processing_logs before dropping table."
                );
                DB::table('livestream_processing_logs')->truncate();
            }

            Schema::drop('livestream_processing_logs');
        }

        if ($this->hasIndex('preachers', 'preachers_name_index')) {
            Schema::table('preachers', function (Blueprint $table): void {
                $table->dropIndex('preachers_name_index');
            });
        }

        if ($this->hasIndex('sermon_processing_steps', 'sermon_processing_steps_processing_id_step_index')) {
            Schema::table('sermon_processing_steps', function (Blueprint $table): void {
                $table->dropIndex('sermon_processing_steps_processing_id_step_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('livestream_processing_logs')) {
            Schema::create('livestream_processing_logs', function (Blueprint $table): void {
                $table->id();
                $table->string('processing_id', 36)->unique();
                $table->string('original_filename', 255);
                $table->string('original_file_path', 500);
                $table->unsignedBigInteger('file_size');
                $table->string('file_format', 10)->nullable();
                $table->float('duration')->nullable();
                $table->enum('status', [
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
                    'failed',
                ])->default('pending');
                $table->text('error_message')->nullable();
                $table->enum('threshold_method', ['fixed', 'adaptive', 'fallback'])->default('fixed');
                $table->decimal('adaptive_threshold', 5, 2)->nullable();
                $table->json('rms_stats')->nullable();
                $table->string('rms_log_path', 500)->nullable();
                $table->string('sermon_audio_path', 500)->nullable();
                $table->string('sermon_video_path', 500)->nullable();
                $table->float('sermon_start_time')->nullable();
                $table->float('sermon_end_time')->nullable();
                $table->string('sermon_processing_id', 36)->nullable();
                $table->unsignedInteger('sermon_id')->nullable();
                $table->json('processing_metadata')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->foreign('sermon_id')->references('id')->on('sermons')->nullOnDelete();
                $table->index('status');
                $table->index('processing_id');
            });
        }

        if (! $this->hasIndex('preachers', 'preachers_name_index')) {
            Schema::table('preachers', function (Blueprint $table): void {
                $table->index('name', 'preachers_name_index');
            });
        }

        if (! $this->hasIndex('sermon_processing_steps', 'sermon_processing_steps_processing_id_step_index')) {
            Schema::table('sermon_processing_steps', function (Blueprint $table): void {
                $table->index(['processing_id', 'step'], 'sermon_processing_steps_processing_id_step_index');
            });
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->whereRaw('table_schema = database()')
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
