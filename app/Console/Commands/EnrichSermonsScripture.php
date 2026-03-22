<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ScriptureOperatorService;
use Illuminate\Console\Command;

class EnrichSermonsScripture extends Command
{
    protected $signature = 'sermons:enrich-scripture
                            {--limit=100 : Maximum number of sermons to process}
                            {--dry-run : Preview which sermons would be enriched without dispatching jobs}
                            {--queue : Dispatch jobs to the queue instead of running synchronously}
                            {--delay=500 : Milliseconds to sleep between API calls when running synchronously}';

    protected $description = 'Backfill scripture text for sermons with references but no linked passage';

    public function handle(ScriptureOperatorService $scriptureOperatorService): int
    {
        if (! config('services.api_bible.enabled')) {
            $this->info('api.bible is disabled (API_BIBLE_ENABLED=false). Skipping.');

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');
        $useQueue = (bool) $this->option('queue');
        $delayMs = (int) $this->option('delay');
        $candidateCount = $scriptureOperatorService->countEnrichmentCandidates($limit);

        if ($candidateCount === 0) {
            $this->info('No sermons need scripture enrichment.');

            return self::SUCCESS;
        }

        $this->info("Found {$candidateCount} sermon(s) to enrich".($dryRun ? ' (dry run)' : '').'.');

        $result = $scriptureOperatorService->runEnrichment(
            limit: $limit,
            dryRun: $dryRun,
            queue: $useQueue,
            delayMs: $delayMs,
            progress: function (string $status, mixed $sermon, string $detail): void {
                if (! $sermon instanceof \App\Models\Sermon) {
                    return;
                }

                $prefix = "  [{$sermon->id}] {$sermon->title} — {$sermon->reference}";

                match ($status) {
                    'dry-run' => $this->line($prefix),
                    'queued' => $this->line("{$prefix}\n    queued"),
                    'resolved', 'updated' => $this->line("{$prefix}\n    resolved: {$detail}"),
                    'not_found' => $this->warn("{$prefix}\n    not_found: {$detail}"),
                    'unparseable' => $this->warn("{$prefix}\n    unparseable: {$detail}"),
                    'rate_limited' => $this->error("{$prefix}\n    rate_limited/server_error: {$detail}"),
                    'failed' => $this->error("{$prefix}\n    failed: {$detail}"),
                    'budget_exceeded' => $this->warn("{$prefix}\n    budget_exceeded"),
                    default => null,
                };
            },
        );

        $counts = $result['summary'];

        if ($result['stopped_early']) {
            $this->warn('  Daily API budget reached — stopping early.');
        }

        if ($useQueue) {
            $this->info("Done. Queued: {$counts['queued']}");
        } else {
            $this->info(sprintf(
                'Done. Resolved: %d, Not found: %d, Unparseable: %d, Rate-limited: %d, Failed: %d, Budget exceeded: %d',
                $counts['resolved'] + $counts['updated'],
                $counts['not_found'],
                $counts['unparseable'],
                $counts['rate_limited'],
                $counts['failed'],
                $counts['budget_exceeded'],
            ));
        }

        return self::SUCCESS;
    }
}
