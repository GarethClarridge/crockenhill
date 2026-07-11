<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Song;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class BackfillSongPraiseNumbersCommand extends Command
{
    protected $signature = 'songs:backfill-praise-numbers
        {--dry-run : Report what would change without writing to the database}';

    protected $description = 'Populate praise_number from the "#NNN" suffix in OpenLP-imported song titles';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;
        $unchanged = 0;
        $withoutNumber = 0;

        Song::query()->withTrashed()->orderBy('id')->chunkById(250, function (Collection $songs) use ($dryRun, &$updated, &$unchanged, &$withoutNumber): void {
            foreach ($songs as $song) {
                $praiseNumber = Song::praiseNumberFromTitle($song->title);

                if ($praiseNumber === null) {
                    $withoutNumber++;

                    continue;
                }

                if ($song->praise_number === $praiseNumber) {
                    $unchanged++;

                    continue;
                }

                if (! $dryRun) {
                    $song->praise_number = $praiseNumber;
                    $song->saveQuietly();
                }

                $updated++;
            }
        });

        $mode = $dryRun ? ' (dry run)' : '';
        $this->info("Praise numbers{$mode}: {$updated} updated, {$unchanged} already set, {$withoutNumber} titles without a number.");

        return self::SUCCESS;
    }
}
