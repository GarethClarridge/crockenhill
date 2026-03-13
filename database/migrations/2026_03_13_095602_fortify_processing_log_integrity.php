<?php

declare(strict_types=1);

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
        // 1. Ensure sermon_processing_steps.processing_id is compatible with media_processing_logs.processing_id
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE media_processing_logs MODIFY processing_id CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
            DB::statement('ALTER TABLE sermon_processing_steps MODIFY processing_id CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        }

        // 2. Add foreign key to sermon_processing_steps.
        // NOTE: The reference column media_processing_logs.processing_id already has a UNIQUE constraint from its creation migration.
        // Any orphaned steps that cannot satisfy this constraint will cause the migration to fail, requiring manual cleanup.
        Schema::table('sermon_processing_steps', function (Blueprint $table) {
            $table->foreign('processing_id')
                ->references('processing_id')
                ->on('media_processing_logs')
                ->onUpdate('cascade')
                ->onDelete('cascade');
        });

        // 3. Update status ENUM on media_processing_logs to include all ProcessingStatus values
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE media_processing_logs MODIFY COLUMN status ENUM('pending', 'started', 'processing', 'completed', 'skipped', 'failed', 'cancelled') NOT NULL DEFAULT 'pending'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sermon_processing_steps', function (Blueprint $table) {
            $table->dropForeign(['processing_id']);
        });

        // NOTE: Reverting the ENUM may cause data loss or migration failure if new statuses are in use.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE media_processing_logs MODIFY COLUMN status ENUM('pending', 'processing', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending'");
        }
    }
};
