<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ScripturePassage;
use App\Services\Scripture\ScriptureOperatorService;
use Illuminate\Console\Command;

class RefreshScripturePassages extends Command
{
    protected $signature = 'scripture:refresh-passages
                            {--dry-run : Preview which stale passages would be refreshed}
                            {--delay=500 : Milliseconds to sleep between API calls (throttle)}';

    protected $description = 'Refresh cached scripture passages older than the configured threshold (api.bible 30-day compliance)';

    public function handle(ScriptureOperatorService $scriptureOperatorService): int
    {
        if (! config('services.api_bible.enabled')) {
            $this->info('api.bible is disabled (API_BIBLE_ENABLED=false). Skipping.');

            return self::SUCCESS;
        }

        $refreshAfterDays = (int) config('services.api_bible.refresh_after_days', 28);
        $dryRun = (bool) $this->option('dry-run');
        $delayMs = (int) $this->option('delay');
        $candidateCount = $scriptureOperatorService->countRefreshCandidates();

        if ($candidateCount === 0) {
            $this->info('No stale passages to refresh.');

            return self::SUCCESS;
        }

        $this->info(
            "Refreshing {$candidateCount} passage(s) older than {$refreshAfterDays} days..."
            .($dryRun ? ' (dry run)' : '')
        );

        $result = $scriptureOperatorService->runRefresh(
            dryRun: $dryRun,
            delayMs: $delayMs,
            progress: function (string $status, mixed $passage, string $detail): void {
                if (! $passage instanceof ScripturePassage) {
                    return;
                }

                $prefix = "  {$passage->normalized_reference}";

                match ($status) {
                    'dry-run' => $this->line($prefix),
                    'updated' => $this->line("{$prefix}\n    updated"),
                    'not_found' => $this->warn("{$prefix}\n    not_found"),
                    'rate_limited' => $this->error("{$prefix}\n    rate_limited/server_error: {$detail}"),
                    'failed' => $this->error("{$prefix}\n    failed: {$detail}"),
                    'budget_exceeded' => $this->warn("{$prefix}\n    budget_exceeded"),
                    default => null,
                };
            },
        );

        if ($result['stopped_early']) {
            $this->warn('  Daily API budget reached — stopping early.');
        }

        $counts = $result['summary'];

        $this->info(sprintf(
            'Done. Updated: %d, Not found: %d, Rate-limited: %d, Failed: %d, Budget exceeded: %d',
            $counts['updated'],
            $counts['not_found'],
            $counts['rate_limited'],
            $counts['failed'],
            $counts['budget_exceeded'],
        ));

        return self::SUCCESS;
    }
}
