<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Explicit indexes that may duplicate a foreign-key backing index, keyed by table.
     *
     * Each listed column is the local side of a foreign key. MySQL only adds its own
     * `<table>_<column>_foreign` backing index when no suitable index already exists, so the matching
     * `<table>_<column>_index` added by
     * 2026_06_14_200000_add_missing_fk_indexes_to_media_processing_logs_and_speaker_samples
     * is only a true duplicate where that `_foreign` index is also present. (The earlier "missing FK index"
     * premise only held on SQLite, which does not auto-index FK columns.)
     *
     * Each value maps the explicit index name to the foreign-key backing index that must also exist
     * before the explicit one can be safely dropped. Where the `_foreign` index is absent, MySQL adopted
     * the explicit `_index` as the foreign key's sole backing index, so dropping it fails with errno 1553
     * ("needed in a foreign key constraint"); the guard below keeps that case a no-op.
     *
     * @var array<string, array<string, string>>
     */
    private array $redundantIndexes = [
        'media_processing_logs' => [
            'media_processing_logs_sermon_id_index' => 'media_processing_logs_sermon_id_foreign',
            'media_processing_logs_owner_user_id_index' => 'media_processing_logs_owner_user_id_foreign',
            'media_processing_logs_church_service_id_index' => 'media_processing_logs_church_service_id_foreign',
        ],
        'speaker_samples' => [
            'speaker_samples_media_processing_log_id_index' => 'speaker_samples_media_processing_log_id_foreign',
        ],
    ];

    /**
     * Drop the redundant foreign-key duplicate indexes where the foreign key retains its own backing index.
     */
    public function up(): void
    {
        foreach ($this->redundantIndexes as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $indexes): void {
                foreach ($indexes as $index => $foreignIndex) {
                    if (Schema::hasIndex($table, $index) && Schema::hasIndex($table, $foreignIndex)) {
                        $blueprint->dropIndex($index);
                    }
                }
            });
        }
    }

    /**
     * Restore the explicit duplicate indexes.
     */
    public function down(): void
    {
        $columnsByIndex = [
            'media_processing_logs_sermon_id_index' => ['media_processing_logs', 'sermon_id'],
            'media_processing_logs_owner_user_id_index' => ['media_processing_logs', 'owner_user_id'],
            'media_processing_logs_church_service_id_index' => ['media_processing_logs', 'church_service_id'],
            'speaker_samples_media_processing_log_id_index' => ['speaker_samples', 'media_processing_log_id'],
        ];

        foreach ($columnsByIndex as $index => [$table, $column]) {
            if (! Schema::hasIndex($table, $index)) {
                Schema::table($table, function (Blueprint $blueprint) use ($column, $index): void {
                    $blueprint->index($column, $index);
                });
            }
        }
    }
};
