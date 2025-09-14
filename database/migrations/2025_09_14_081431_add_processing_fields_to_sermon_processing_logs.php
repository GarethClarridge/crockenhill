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
        Schema::table('sermon_processing_logs', function (Blueprint $table) {
            $table->string('stored_file_path')->nullable()->after('original_filename');
            $table->string('transcript_path')->nullable()->after('stored_file_path');
            $table->json('ai_analysis')->nullable()->after('transcript_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sermon_processing_logs', function (Blueprint $table) {
            $table->dropColumn(['stored_file_path', 'transcript_path', 'ai_analysis']);
        });
    }
};
