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
        $threshold = now()->subDays($refreshAfterDays);
        $delayMs = (int) $this->option('delay');

        $passages = ScripturePassage::where('fetched_at', '<', $threshold)->get();

        if ($passages->isEmpty()) {
            $this->info('No stale passages to refresh.');

            return self::SUCCESS;
        }

        $this->info("Refreshing {$passages->count()} passage(s) older than {$refreshAfterDays} days...");

        $updated = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($passages as $passage) {
            try {
                $result = $passage->api_passage_id
                    ? $client->fetchPassageById($passage->api_passage_id)
                    : $client->searchPassage($passage->normalized_reference);

                if ($result === null) {
                    $this->warn("  Not found: {$passage->normalized_reference}");
                    $skipped++;

                    continue;
                }

                $sanitizedHtml = $sanitizer->sanitize($result->htmlContent);

                if ($sanitizedHtml === null) {
                    $this->warn("  Empty HTML after sanitize: {$passage->normalized_reference}");
                    $skipped++;

                    continue;
                }

                $passage->update([
                    'html_content' => $sanitizedHtml,
                    'copyright' => $result->copyright,
                    'fums_token' => $result->fumsToken,
                    'fetched_at' => now(),
                ]);

                $this->line("  Refreshed: {$passage->normalized_reference}");
                $updated++;
            } catch (\Throwable $e) {
                $this->error("  Failed: {$passage->normalized_reference} — {$e->getMessage()}");
                Log::error('scripture:refresh-passages failed for passage', [
                    'passage_id' => $passage->id,
                    'reference' => $passage->normalized_reference,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $this->info("Done. Updated: {$updated}, Skipped: {$skipped}, Failed: {$failed}");

        return self::SUCCESS;
    }
}
