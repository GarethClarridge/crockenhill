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
        Schema::table('media_processing_logs', function (Blueprint $table): void {
            $table->string('queue_name')->nullable()->after('processing_metadata');
            $table->string('batch_id', 36)->nullable()->after('queue_name');
            $table->string('job_id')->nullable()->after('batch_id');
            $table->unsignedSmallInteger('attempt_count')->nullable()->after('job_id');
        });
    }

    public function down(): void
    {
        Schema::table('media_processing_logs', function (Blueprint $table): void {
            $table->dropColumn(['queue_name', 'batch_id', 'job_id', 'attempt_count']);
        });
    }
};
