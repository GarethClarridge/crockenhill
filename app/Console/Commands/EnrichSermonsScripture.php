<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\QueueScriptureEnrichment;
use App\Jobs\FetchBibleTextForSermon;
use App\Models\Sermon;
use Illuminate\Console\Command;

class EnrichSermonsScripture extends Command
{
    protected $signature = 'sermons:enrich-scripture
                            {--limit=100 : Maximum number of sermons to process}
                            {--dry-run : Preview which sermons would be enriched without dispatching jobs}
                            {--queue : Dispatch jobs to the queue instead of running synchronously}
                            {--delay=500 : Milliseconds to sleep between dispatches when not queuing}';

    protected $description = 'Backfill scripture text for sermons with references but no linked passage';

    public function handle(QueueScriptureEnrichment $enrichment): int
    {
        if (! config('services.api_bible.enabled')) {
            $this->info('api.bible is disabled (API_BIBLE_ENABLED=false). Skipping.');

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');
        $useQueue = (bool) $this->option('queue');
        $delayMs = (int) $this->option('delay');

        $sermons = Sermon::query()
            ->whereNotNull('reference')
            ->where('reference', '!=', '')
            ->whereNull('scripture_passage_id')
            ->limit($limit)
            ->get();

        if ($sermons->isEmpty()) {
            $this->info('No sermons need scripture enrichment.');

            return self::SUCCESS;
        }

        $this->info("Found {$sermons->count()} sermon(s) to enrich".($dryRun ? ' (dry run)' : '').'.');

        $processed = 0;

        foreach ($sermons as $sermon) {
            $this->line("  [{$sermon->id}] {$sermon->title} — {$sermon->reference}");

            if ($dryRun) {
                $processed++;

                continue;
            }

            if ($useQueue) {
                $enrichment->dispatch($sermon);
            } else {
                FetchBibleTextForSermon::dispatchSync($sermon);
            }

            $processed++;

            if (! $useQueue && $delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $this->info("Done. Processed: {$processed}");

        return self::SUCCESS;
    }
}
