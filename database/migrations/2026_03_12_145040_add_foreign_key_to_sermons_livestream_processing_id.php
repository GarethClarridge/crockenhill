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
        // Ensure no orphaned records exist before adding the constraint
        DB::table('sermons')
            ->whereNotNull('livestream_processing_id')
            ->whereNotIn('livestream_processing_id', function ($query) {
                $query->select('processing_id')->from('media_processing_logs');
            })
            ->update(['livestream_processing_id' => null]);

        // Normalize collation to ensure compatibility for the foreign key constraint
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE media_processing_logs MODIFY processing_id VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
            DB::statement('ALTER TABLE sermons MODIFY livestream_processing_id VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');
        }

        Schema::table('sermons', function (Blueprint $table) {
            $table->foreign('livestream_processing_id')
                ->references('processing_id')
                ->on('media_processing_logs')
                ->onUpdate('cascade')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sermons', function (Blueprint $table) {
            $table->dropForeign(['livestream_processing_id']);
        });
    }
};
