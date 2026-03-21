<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->hasIndex('speaker_samples', 'speaker_samples_profile_sermon_source_unique')) {
            $duplicateGroups = DB::table('speaker_samples')
                ->whereNotNull('sermon_id')
                ->selectRaw('speaker_profile_id, sermon_id, source, COUNT(*) as duplicate_count')
                ->groupBy('speaker_profile_id', 'sermon_id', 'source')
                ->havingRaw('COUNT(*) > 1')
                ->count();

            if ($duplicateGroups > 0) {
                throw new RuntimeException(
                    'Refusing to add speaker sample uniqueness while duplicate identity rows still exist. '
                    .'Run "php artisan schema:audit-guardrails" and resolve the duplicates first.'
                );
            }

            Schema::table('speaker_samples', function (Blueprint $table): void {
                $table->unique(
                    ['speaker_profile_id', 'sermon_id', 'source'],
                    'speaker_samples_profile_sermon_source_unique'
                );
            });
        }

        Schema::table('media_processing_logs', function (Blueprint $table): void {
            $table->dropForeign(['sermon_id']);
        });

        Schema::table('media_processing_logs', function (Blueprint $table): void {
            $table->foreign('sermon_id')
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
        if ($this->hasIndex('speaker_samples', 'speaker_samples_profile_sermon_source_unique')) {
            Schema::table('speaker_samples', function (Blueprint $table): void {
                $table->dropUnique('speaker_samples_profile_sermon_source_unique');
            });
        }

        Schema::table('media_processing_logs', function (Blueprint $table): void {
            $table->dropForeign(['sermon_id']);
        });

        Schema::table('media_processing_logs', function (Blueprint $table): void {
            // Rolling back restores the legacy destructive behavior for future sermon deletions:
            // existing NULL sermon links remain valid, but newly deleted sermons will cascade
            // and remove linked processing logs again.
            $table->foreign('sermon_id')
                ->references('id')
                ->on('sermons')
                ->cascadeOnDelete();
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->whereRaw('table_schema = database()')
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
