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

        $this->dropLivestreamSegmentForeignKeys();

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

        $this->dropLivestreamSegmentForeignKeys();

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

    private function dropLivestreamSegmentForeignKeys(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            Schema::table('livestream_segments', function (Blueprint $table): void {
                $table->dropForeign(['processing_id']);
                $table->dropForeign(['media_processing_log_id']);
            });

            return;
        }

        $constraints = DB::select(
            "SELECT DISTINCT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'livestream_segments'
              AND REFERENCED_TABLE_NAME IS NOT NULL
              AND COLUMN_NAME IN ('processing_id', 'processing_log_id', 'media_processing_log_id')"
        );

        foreach ($constraints as $constraint) {
            if (isset($constraint->CONSTRAINT_NAME) && is_string($constraint->CONSTRAINT_NAME)) {
                Schema::table('livestream_segments', function (Blueprint $table) use ($constraint): void {
                    $table->dropForeign($constraint->CONSTRAINT_NAME);
                });
            }
        }
    }
};
