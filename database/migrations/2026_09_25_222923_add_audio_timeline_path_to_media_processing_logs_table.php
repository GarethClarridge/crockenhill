<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the run's music/speech timeline lives, beside the RMS log it is read with
 * (`ClassifyServiceAudio`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_processing_logs', function (Blueprint $table): void {
            $table->string('audio_timeline_path')->nullable()->after('rms_log_path');
        });
    }

    public function down(): void
    {
        Schema::table('media_processing_logs', function (Blueprint $table): void {
            $table->dropColumn('audio_timeline_path');
        });
    }
};
