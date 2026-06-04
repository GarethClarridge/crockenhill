<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Song\LegacyPlayDateSongUsageImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportLegacySongUsageCommand extends Command
{
    protected $signature = 'service-tracking:import-legacy-song-usage
                            {--path= : Path to the SQL dump that contains legacy play_date rows}
                            {--dry-run : Preview the legacy import without writing any database changes}';

    protected $description = 'Backfill legacy play_date song usage into church services and service items';

    public function handle(LegacyPlayDateSongUsageImporter $importer): int
    {
        try {
            $metrics = $importer->import(
                path: $this->option('path'),
                dryRun: (bool) $this->option('dry-run')
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Legacy song usage import completed.');
        $this->line('Path: '.$metrics['path']);

        if ($metrics['dry_run']) {
            $this->warn('Dry run enabled. No database changes were written.');
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Rows parsed', (string) $metrics['rows_parsed']],
                ['Distinct song IDs', (string) $metrics['distinct_song_ids']],
                ['Services seen', (string) $metrics['services_seen']],
                ['Services created', (string) $metrics['services_created']],
                ['Services reused', (string) $metrics['services_reused']],
                ['Items imported', (string) $metrics['items_imported']],
                ['Items skipped (existing legacy row)', (string) $metrics['items_skipped_existing_row']],
                ['Items skipped (existing song on service)', (string) $metrics['items_skipped_existing_song']],
            ]
        );

        return self::SUCCESS;
    }
}
