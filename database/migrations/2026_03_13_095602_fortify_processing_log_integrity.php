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
        // We use Schema::table with change() for better portability and consistency with standards.
        if (DB::getDriverName() === 'mysql') {
            Schema::table('media_processing_logs', function (Blueprint $table) {
                $table->char('processing_id', 36)->charset('utf8mb4')->collation('utf8mb4_unicode_ci')->change();
            });
            Schema::table('sermon_processing_steps', function (Blueprint $table) {
                $table->char('processing_id', 36)->charset('utf8mb4')->collation('utf8mb4_unicode_ci')->change();
            });
        }

        // 2. Add foreign key to sermon_processing_steps.
        // Orphaned records cleanup is omitted here to follow Warden standards (avoiding data modification in schema migrations).
        // If orphaned records exist, this migration will fail and require manual intervention.
        Schema::table('sermon_processing_steps', function (Blueprint $table) {
            $table->foreign('processing_id')
                ->references('processing_id')
                ->on('media_processing_logs')
                ->onUpdate('cascade')
                ->onDelete('cascade');
        });

        // 3. Update status ENUM on media_processing_logs to include all ProcessingStatus values
        if (DB::getDriverName() === 'mysql') {
            Schema::table('media_processing_logs', function (Blueprint $table) {
                $table->enum('status', ['pending', 'started', 'processing', 'completed', 'skipped', 'failed', 'cancelled'])
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
        Schema::table('sermon_processing_steps', function (Blueprint $table) {
            $table->dropForeign(['processing_id']);
        });

        // We do not revert the ENUM values in down() to prevent data truncation if new statuses are in use.
        // This preserves data integrity for existing records.
    }
};
