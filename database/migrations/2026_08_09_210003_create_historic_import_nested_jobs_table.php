<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historic_import_nested_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('historic_import_operation_id');
            $table->foreignId('media_processing_log_id');
            $table->string('job_key');
            $table->string('job_type', 120);
            $table->string('state', 24);
            $table->unsignedInteger('attempts')->default(0);
            $table->char('error_fingerprint', 64)->nullable();
            $table->timestamp('dispatched_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['historic_import_operation_id', 'job_key'], 'historic_nested_operation_job_unique');
            $table->foreign('historic_import_operation_id', 'historic_nested_operation_foreign')
                ->references('id')->on('historic_import_operations')->restrictOnDelete();
            $table->foreign('media_processing_log_id', 'historic_nested_processing_foreign')
                ->references('id')->on('media_processing_logs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historic_import_nested_jobs');
    }
};
