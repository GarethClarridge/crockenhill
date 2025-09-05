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
            $table->enum('source_type', ['audio', 'video', 'livestream'])->default('audio')->after('processing_id');
            $table->json('source_metadata')->nullable()->after('error_message');

            // Add index for efficient queries by source type
            $table->index(['source_type', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sermon_processing_logs', function (Blueprint $table) {
            $table->dropIndex(['source_type', 'status']);
            $table->dropColumn(['source_type', 'source_metadata']);
        });
    }
};
