<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_processing_logs', function (Blueprint $table) {
            if (! Schema::hasIndex('media_processing_logs', 'media_processing_logs_sermon_id_index')) {
                $table->index('sermon_id');
            }
            if (! Schema::hasIndex('media_processing_logs', 'media_processing_logs_owner_user_id_index')) {
                $table->index('owner_user_id');
            }
            if (! Schema::hasIndex('media_processing_logs', 'media_processing_logs_church_service_id_index')) {
                $table->index('church_service_id');
            }
        });

        Schema::table('speaker_samples', function (Blueprint $table) {
            if (! Schema::hasIndex('speaker_samples', 'speaker_samples_media_processing_log_id_index')) {
                $table->index('media_processing_log_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('media_processing_logs', function (Blueprint $table) {
            if (Schema::hasIndex('media_processing_logs', 'media_processing_logs_sermon_id_index')) {
                $table->dropIndex('media_processing_logs_sermon_id_index');
            }
            if (Schema::hasIndex('media_processing_logs', 'media_processing_logs_owner_user_id_index')) {
                $table->dropIndex('media_processing_logs_owner_user_id_index');
            }
            if (Schema::hasIndex('media_processing_logs', 'media_processing_logs_church_service_id_index')) {
                $table->dropIndex('media_processing_logs_church_service_id_index');
            }
        });

        Schema::table('speaker_samples', function (Blueprint $table) {
            if (Schema::hasIndex('speaker_samples', 'speaker_samples_media_processing_log_id_index')) {
                $table->dropIndex('speaker_samples_media_processing_log_id_index');
            }
        });
    }
};
