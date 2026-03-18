<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\MoveSermonToPrivateStorage;
use App\Models\Sermon;
use Illuminate\Console\Command;

class MoveChildrensTalksToPrivateStorage extends Command
{
    protected $signature = 'sermons:move-childrens-talks-to-private
                            {--dry-run : Preview which sermons would be moved without making changes}
                            {--batch-size=50 : Number of sermons to process per chunk}';

    protected $description = 'Move Children\'s Talk audio and thumbnails from public to private storage';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $batchSize = (int) $this->option('batch-size');

        $query = Sermon::whereChildrensTalk()
            ->where(function ($q) {
                $q->where('audio_file_path', 'not like', 'private/%')
                    ->orWhereNull('audio_file_path');
            })
            ->whereNotNull('audio_file_path');

        $total = $query->count();

        if ($total === 0) {
            $this->info('No Children\'s Talk sermons require migration.');

            return self::SUCCESS;
        }

        if ($isDryRun) {
            $this->info("Dry run: {$total} sermon(s) would be dispatched for private storage migration.");

            $rows = $query->select(['id', 'title', 'audio_file_path'])->get()
                ->map(fn ($s) => [$s->id, $s->title, $s->audio_file_path])
                ->toArray();

            $this->table(['ID', 'Title', 'Current Audio Path'], $rows);

            return self::SUCCESS;
        }

        $dispatched = 0;

        $query->select('id')->chunk($batchSize, function ($sermons) use (&$dispatched) {
            foreach ($sermons as $sermon) {
                MoveSermonToPrivateStorage::dispatch($sermon->id);
                $dispatched++;
            }
        });

        $this->info("Dispatched {$dispatched} job(s) to move Children's Talks to private storage.");

        return self::SUCCESS;
    }
}
