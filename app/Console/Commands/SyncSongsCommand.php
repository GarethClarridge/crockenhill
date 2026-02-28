<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SongCatalogSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncSongsCommand extends Command
{
    protected $signature = 'service-tracking:sync-songs
                            {--path= : Path to OpenLP songs SQLite file}
                            {--dry-run : Preview sync changes without persisting anything}';

    protected $description = 'Sync OpenLP songs catalog into canonical songs/authors/songbooks tables';

    public function handle(SongCatalogSyncService $syncService): int
    {
        try {
            $metrics = $syncService->sync(
                path: $this->option('path'),
                dryRun: (bool) $this->option('dry-run')
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Song catalog sync completed.');
        $this->line('Path: '.$metrics['path']);

        if ($metrics['dry_run']) {
            $this->warn('Dry run enabled. No database changes were written.');
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Source songs', (string) $metrics['source_songs']],
                ['Canonical groups', (string) $metrics['canonical_groups']],
                ['Duplicate groups', (string) $metrics['duplicate_groups']],
                ['Duplicate rows merged', (string) $metrics['duplicate_rows']],
                ['Songs upserted', (string) $metrics['songs_upserted']],
                ['Songs created', (string) $metrics['songs_created']],
                ['Songs updated', (string) $metrics['songs_updated']],
                ['Songs restored', (string) $metrics['songs_restored']],
                ['Song authors upserted', (string) $metrics['song_authors_upserted']],
                ['Song books upserted', (string) $metrics['song_books_upserted']],
                ['Song-author links synced', (string) $metrics['song_author_links_synced']],
                ['Song-book links synced', (string) $metrics['song_book_links_synced']],
                ['Groups with parse warnings', (string) $metrics['groups_with_parse_warnings']],
            ]
        );

        return self::SUCCESS;
    }
}
