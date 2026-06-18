<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes that duplicate a foreign-key backing index, keyed by table.
     *
     * Each listed column is the local side of a foreign key, so MySQL/MariaDB already
     * maintain a `<table>_<column>_foreign` index for it. The matching `<table>_<column>_index`
     * added by 2026_06_14_200000_add_missing_fk_indexes_to_media_processing_logs_and_speaker_samples
     * therefore duplicates that coverage on the production engine for no read benefit. (The earlier
     * "missing FK index" premise only held on SQLite, which does not auto-index FK columns.)
     *
     * @var array<string, list<string>>
     */
    private array $redundantIndexes = [
        'media_processing_logs' => [
            'media_processing_logs_sermon_id_index',
            'media_processing_logs_owner_user_id_index',
            'media_processing_logs_church_service_id_index',
        ],
        'speaker_samples' => [
            'speaker_samples_media_processing_log_id_index',
        ],
    ];

    /**
     * Drop the redundant foreign-key duplicate indexes where present.
     */
    public function up(): void
    {
        foreach ($this->redundantIndexes as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $indexes): void {
                foreach ($indexes as $index) {
                    if (Schema::hasIndex($table, $index)) {
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
