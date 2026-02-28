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
        Schema::table('service_sections', function (Blueprint $table): void {
            $table->string('publication_status')
                ->default('not_applicable')
                ->after('metadata');
            $table->unsignedInteger('published_sermon_id')
                ->nullable()
                ->after('publication_status');
            $table->timestamp('published_at')
                ->nullable()
                ->after('published_sermon_id');
            $table->string('extracted_video_path')
                ->nullable()
                ->after('published_at');
            $table->string('extracted_audio_path')
                ->nullable()
                ->after('extracted_video_path');
            $table->timestamp('extracted_at')
                ->nullable()
                ->after('extracted_audio_path');
            $table->timestamp('unpublished_expires_at')
                ->nullable()
                ->after('extracted_at');

            $table->index('publication_status', 'service_sections_publication_status_index');
            $table->index('unpublished_expires_at', 'service_sections_unpublished_expires_at_index');
            $table->index('published_sermon_id', 'service_sections_published_sermon_id_index');
            $table->unique('published_sermon_id', 'service_sections_published_sermon_id_unique');
            $table->foreign('published_sermon_id', 'service_sections_published_sermon_id_foreign')
                ->references('id')
                ->on('sermons')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_sections', function (Blueprint $table): void {
            $table->dropUnique('service_sections_published_sermon_id_unique');
            $table->dropIndex('service_sections_publication_status_index');
            $table->dropIndex('service_sections_unpublished_expires_at_index');
            $table->dropIndex('service_sections_published_sermon_id_index');
            $table->dropForeign('service_sections_published_sermon_id_foreign');
            $table->dropColumn([
                'publication_status',
                'published_sermon_id',
                'published_at',
                'extracted_video_path',
                'extracted_audio_path',
                'extracted_at',
                'unpublished_expires_at',
            ]);
        });
    }
};
