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
        Schema::table('church_services', function (Blueprint $table) {
            $table->string('pending_structure_merge_source')->nullable()->after('import_metadata');
            $table->index('pending_structure_merge_source', 'church_services_pending_merge_source_index');
        });

        // Backfill: extract pending_structure_merge.incoming_source from import_metadata JSON for existing rows
        DB::table('church_services')
            ->whereNotNull('import_metadata')
            ->whereRaw("JSON_CONTAINS_PATH(import_metadata, 'one', '$.pending_structure_merge.incoming_source')")
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $importMetadata = json_decode($row->import_metadata, associative: true) ?? [];
                    $incomingSource = $importMetadata['pending_structure_merge']['incoming_source'] ?? null;

                    if (is_string($incomingSource)) {
                        DB::table('church_services')
                            ->where('id', $row->id)
                            ->update(['pending_structure_merge_source' => $incomingSource]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('church_services', function (Blueprint $table) {
            $table->dropColumn('pending_structure_merge_source');
        });
    }
};
