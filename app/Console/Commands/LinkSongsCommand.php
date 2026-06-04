<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ChurchService\ChurchServiceSongLinker;
use Illuminate\Console\Command;
use Throwable;

class LinkSongsCommand extends Command
{
    protected $signature = 'service-tracking:link-songs
                            {--dry-run : Preview linking changes without persisting anything}';

    protected $description = 'Link imported service song items to canonical songs using deterministic canonical keys';

    public function handle(ChurchServiceSongLinker $songLinker): int
    {
        try {
            $metrics = $songLinker->linkAll(dryRun: (bool) $this->option('dry-run'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Song linking run completed.');

        if ($metrics['dry_run']) {
            $this->warn('Dry run enabled. No database changes were written.');
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Song items processed', (string) $metrics['processed']],
                ['Matched items', (string) $metrics['matched']],
                ['Unmatched items', (string) $metrics['unmatched']],
                ['Links updated', (string) $metrics['updated']],
                ['Links cleared', (string) $metrics['cleared']],
                ['Unchanged', (string) $metrics['unchanged']],
            ]
        );

        return self::SUCCESS;
    }
}
