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
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // Clean up orphaned segments that reference non-existent processing logs
        DB::table('livestream_segments')
            ->whereNotIn('processing_id', function ($query): void {
                $query->select('processing_id')->from('media_processing_logs');
            })
            ->delete();

        DB::table('livestream_segments')
            ->whereNotIn('media_processing_log_id', function ($query): void {
                $query->select('id')->from('media_processing_logs');
            })
            ->delete();

        Schema::table('livestream_segments', function (Blueprint $table): void {
            $table->dropForeign(['processing_id']);
            $table->dropForeign(['media_processing_log_id']);
        });

        Schema::table('livestream_segments', function (Blueprint $table): void {
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
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('livestream_segments', function (Blueprint $table): void {
            $table->dropForeign(['processing_id']);
            $table->dropForeign(['media_processing_log_id']);
        });

        // Only restore old foreign keys if the old table exists
        if (Schema::hasTable('livestream_processing_logs')) {
            Schema::table('livestream_segments', function (Blueprint $table): void {
                // Restore old foreign keys
                $table->foreign('processing_id')
                    ->references('processing_id')
                    ->on('livestream_processing_logs')
                    ->onDelete('cascade');

                $table->foreign('media_processing_log_id')
                    ->references('id')
                    ->on('livestream_processing_logs')
                    ->onDelete('cascade');
            });
        }
    }
};
