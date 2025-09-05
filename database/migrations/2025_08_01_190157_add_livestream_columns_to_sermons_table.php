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
        Schema::table('sermons', function (Blueprint $table) {
            // Only add livestream_processing_id if it doesn't exist
            if (! Schema::hasColumn('sermons', 'livestream_processing_id')) {
                $table->string('livestream_processing_id', 36)->nullable()->after('id');
            }

            // Add video_file_path after filename
            if (! Schema::hasColumn('sermons', 'video_file_path')) {
                $table->string('video_file_path', 500)->nullable()->after('filename');
            }

            // Add source_type enum
            if (! Schema::hasColumn('sermons', 'source_type')) {
                $table->enum('source_type', ['manual', 'audio_upload', 'livestream'])->default('manual')->after('video_file_path');
            }

            // Add segment timing columns
            if (! Schema::hasColumn('sermons', 'segment_start_time')) {
                $table->decimal('segment_start_time', 10, 3)->nullable()->after('source_type');
            }

            if (! Schema::hasColumn('sermons', 'segment_end_time')) {
                $table->decimal('segment_end_time', 10, 3)->nullable()->after('segment_start_time');
            }

            // Add indexes if they don't exist
            if (! Schema::hasIndex('sermons', 'sermons_livestream_processing_id_index')) {
                $table->index('livestream_processing_id');
            }

            if (! Schema::hasIndex('sermons', 'sermons_source_type_index')) {
                $table->index('source_type');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sermons', function (Blueprint $table) {
            // Drop indexes if they exist
            if (Schema::hasIndex('sermons', 'sermons_livestream_processing_id_index')) {
                $table->dropIndex(['livestream_processing_id']);
            }

            if (Schema::hasIndex('sermons', 'sermons_source_type_index')) {
                $table->dropIndex(['source_type']);
            }

            // Drop columns if they exist
            $columnsToDrop = [
                'livestream_processing_id',
                'video_file_path',
                'source_type',
                'segment_start_time',
                'segment_end_time',
            ];

            foreach ($columnsToDrop as $column) {
                if (Schema::hasColumn('sermons', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
