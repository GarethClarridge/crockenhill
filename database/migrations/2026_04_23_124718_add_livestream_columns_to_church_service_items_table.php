<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('church_service_items', function (Blueprint $table) {
            $table->string('livestream_processing_id', 36)->nullable()->after('metadata');
            $table->unsignedBigInteger('livestream_service_section_id')->nullable()->after('livestream_processing_id');
            $table->foreign('livestream_service_section_id')->references('id')->on('service_sections')->nullOnDelete();
            $table->index('livestream_processing_id', 'church_service_items_livestream_processing_id_index');
        });

        // Backfill: extract livestream_projection metadata from JSON for existing rows
        DB::table('church_service_items')
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $metadata = json_decode($row->metadata, associative: true) ?? [];
                    $livestreamProjection = $metadata['livestream_projection'] ?? null;

                    if (is_array($livestreamProjection)) {
                        $processingId = $livestreamProjection['processing_id'] ?? null;
                        $serviceSectionId = $livestreamProjection['service_section_id'] ?? null;

                        if (is_string($processingId)) {
                            // Verify that the service section exists before writing the FK
                            $sectionExists = DB::table('service_sections')
                                ->where('id', $serviceSectionId)
                                ->exists();

                            DB::table('church_service_items')
                                ->where('id', $row->id)
                                ->update([
                                    'livestream_processing_id' => $processingId,
                                    'livestream_service_section_id' => $sectionExists ? $serviceSectionId : null,
                                ]);
                        }
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('church_service_items', function (Blueprint $table) {
            $table->dropForeign(['livestream_service_section_id']);
            $table->dropIndex('church_service_items_livestream_processing_id_index');
            $table->dropColumn('livestream_processing_id', 'livestream_service_section_id');
        });
    }
};
