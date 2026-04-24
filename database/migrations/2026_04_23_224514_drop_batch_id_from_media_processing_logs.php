<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop `batch_id` from `media_processing_logs`.
 *
 * The column was added speculatively alongside `queue_name`, `job_id`, and
 * `attempt_count`, but no processing job in this application is `Batchable`,
 * so the column can never be populated. Keeping it invites misuse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_processing_logs', function (Blueprint $table): void {
            $table->dropColumn('batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('media_processing_logs', function (Blueprint $table): void {
            $table->string('batch_id', 36)->nullable()->after('queue_name');
        });
    }
};
