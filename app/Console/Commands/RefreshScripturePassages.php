<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ScripturePassage;
use App\Services\ApiBibleClient;
use App\Services\ScriptureHtmlSanitizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshScripturePassages extends Command
{
    protected $signature = 'scripture:refresh-passages
                            {--delay=500 : Milliseconds to sleep between API calls (throttle)}';

    protected $description = 'Refresh cached scripture passages older than the configured threshold (api.bible 30-day compliance)';

    public function handle(ApiBibleClient $client, ScriptureHtmlSanitizer $sanitizer): int
    {
        if (! config('services.api_bible.enabled')) {
            $this->info('api.bible is disabled (API_BIBLE_ENABLED=false). Skipping.');

            return self::SUCCESS;
        }

        $refreshAfterDays = (int) config('services.api_bible.refresh_after_days', 28);
        $delayMs = (int) $this->option('delay');

        $passages = ScripturePassage::where('fetched_at', '<', now()->subDays($refreshAfterDays))->get();

        if ($passages->isEmpty()) {
            $this->info('No stale passages to refresh.');

            return self::SUCCESS;
        }

        $this->info("Refreshing {$passages->count()} passage(s) older than {$refreshAfterDays} days...");

        $counts = ['updated' => 0, 'not_found' => 0, 'rate_limited' => 0, 'failed' => 0, 'budget_exceeded' => 0];

        foreach ($passages as $passage) {
            if (! $client->hasDailyBudget()) {
                $this->warn('  Daily API budget reached — stopping early.');
                $counts['budget_exceeded'] += $passages->count() - array_sum($counts);

                break;
            }

            try {
                $result = $passage->api_passage_id
                    ? $client->fetchPassageById($passage->api_passage_id)
                    : $client->searchPassage($passage->normalized_reference);

                if ($result === null) {
                    $this->warn("  not_found: {$passage->normalized_reference}");
                    $counts['not_found']++;

                    continue;
                }

                $sanitizedHtml = $sanitizer->sanitize($result->htmlContent);

                if ($sanitizedHtml === null) {
                    $this->warn("  failed (empty HTML after sanitize): {$passage->normalized_reference}");
                    $counts['failed']++;

                    continue;
                }

                $passage->update([
                    'html_content' => $sanitizedHtml,
                    'copyright' => $result->copyright,
                    'fums_token' => $result->fumsToken,
                    'fetched_at' => now(),
                ]);

                $this->line("  updated: {$passage->normalized_reference}");
                $counts['updated']++;
            } catch (\RuntimeException $e) {
                // Thrown by ApiBibleClient when rate-limited, server error, or budget exhausted after retries
                $this->error("  rate_limited/server_error: {$passage->normalized_reference} — {$e->getMessage()}");
                Log::warning('scripture:refresh-passages rate-limited or server error', [
                    'passage_id' => $passage->id,
                    'reference' => $passage->normalized_reference,
                    'error' => $e->getMessage(),
                    'result_category' => 'rate_limited',
                ]);
                $counts['rate_limited']++;
            } catch (\Throwable $e) {
                $this->error("  failed: {$passage->normalized_reference} — {$e->getMessage()}");
                Log::error('scripture:refresh-passages failed for passage', [
                    'passage_id' => $passage->id,
                    'reference' => $passage->normalized_reference,
                    'error' => $e->getMessage(),
                    'result_category' => 'failed',
                ]);
                $counts['failed']++;
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

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
