<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Song\SongCatalogSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncSongsCommand extends Command
{
    protected $signature = 'service-tracking:sync-songs
                            {--path= : Path to OpenLP songs SQLite file}
                            {--dry-run : Preview sync changes without persisting anything}';

    protected $description = 'Sync OpenLP songs catalogue into canonical songs/authors/songbooks tables';

    public function handle(SongCatalogSyncService $syncService): int
    {
        try {
            $report = $syncService->sync(
                path: $this->option('path'),
                dryRun: (bool) $this->option('dry-run')
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Song catalogue sync completed.');
        $this->line('Path: '.$report->path);

        if ($report->dryRun) {
            $this->warn('Dry run enabled. No database changes were written.');
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Source songs', (string) $report->sourceSongs],
                ['Canonical groups', (string) $report->canonicalGroups],
                ['Duplicate groups', (string) $report->duplicateGroups],
                ['Duplicate rows merged', (string) $report->duplicateRows],
                ['Songs upserted', (string) $report->songsUpserted],
                ['Songs created', (string) $report->songsCreated],
                ['Songs updated', (string) $report->songsUpdated],
                ['Songs restored', (string) $report->songsRestored],
                ['Song authors upserted', (string) $report->songAuthorsUpserted],
                ['Song books upserted', (string) $report->songBooksUpserted],
                ['Song-author links synced', (string) $report->songAuthorLinksSynced],
                ['Song-book links synced', (string) $report->songBookLinksSynced],
                ['Groups with parse warnings', (string) $report->groupsWithParseWarnings],
            ]
        );

        return self::SUCCESS;
    }
}
