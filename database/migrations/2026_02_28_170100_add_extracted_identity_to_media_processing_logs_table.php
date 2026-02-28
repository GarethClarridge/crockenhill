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
            $table->date('extracted_date')->nullable()->after('duration');
            $table->string('extracted_service')->nullable()->after('extracted_date');
            $table->index(
                ['extracted_date', 'extracted_service'],
                'media_processing_logs_extracted_identity_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_processing_logs', function (Blueprint $table): void {
            $table->dropIndex('media_processing_logs_extracted_identity_index');
            $table->dropColumn(['extracted_date', 'extracted_service']);
        });
    }
};
