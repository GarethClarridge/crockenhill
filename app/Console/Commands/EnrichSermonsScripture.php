<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\FetchBibleTextForSermon;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Services\ApiBibleClient;
use App\Services\ScriptureHtmlSanitizer;
use App\Services\ScriptureReferenceResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EnrichSermonsScripture extends Command
{
    protected $signature = 'sermons:enrich-scripture
                            {--limit=100 : Maximum number of sermons to process}
                            {--dry-run : Preview which sermons would be enriched without dispatching jobs}
                            {--queue : Dispatch jobs to the queue instead of running synchronously}
                            {--delay=500 : Milliseconds to sleep between API calls when running synchronously}';

    protected $description = 'Backfill scripture text for sermons with references but no linked passage';

    public function handle(
        ApiBibleClient $client,
        ScriptureReferenceResolver $resolver,
        ScriptureHtmlSanitizer $sanitizer,
    ): int {
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

        $counts = ['resolved' => 0, 'not_found' => 0, 'unparseable' => 0, 'rate_limited' => 0, 'failed' => 0, 'queued' => 0];

        foreach ($sermons as $sermon) {
            $this->line("  [{$sermon->id}] {$sermon->title} — {$sermon->reference}");

            if ($dryRun) {
                continue;
            }

            if ($useQueue) {
                FetchBibleTextForSermon::dispatch($sermon);
                $counts['queued']++;

                continue;
            }

            if (! $client->hasDailyBudget()) {
                $this->warn('  Daily API budget reached — stopping early.');

                break;
            }

            $normalizedReference = $resolver->normalize((string) $sermon->reference);

            if ($normalizedReference === null) {
                $this->warn("    unparseable: {$sermon->reference}");
                $counts['unparseable']++;

                continue;
            }

            $bibleId = (string) config('services.api_bible.default_bible_id');

            try {
                $existing = ScripturePassage::where('bible_id', $bibleId)
                    ->where('normalized_reference', $normalizedReference)
                    ->where('fetched_at', '>=', now()->subDays((int) config('services.api_bible.refresh_after_days', 28)))
                    ->first();

                if ($existing) {
                    $sermon->update(['scripture_passage_id' => $existing->id]);
                    $this->line("    resolved (cache): {$normalizedReference}");
                    $counts['resolved']++;

                    continue;
                }

                $result = $client->searchPassage($normalizedReference);

                if ($result === null) {
                    $this->warn("    not_found: {$normalizedReference}");
                    Log::info('sermons:enrich-scripture passage not found', [
                        'sermon_id' => $sermon->id,
                        'reference' => $normalizedReference,
                        'result_category' => 'not_found',
                    ]);
                    $counts['not_found']++;

                    continue;
                }

                $sanitizedHtml = $sanitizer->sanitize($result->htmlContent);

                if ($sanitizedHtml === null) {
                    $this->warn("    failed (empty HTML after sanitize): {$normalizedReference}");
                    $counts['failed']++;

                    continue;
                }

                $passage = ScripturePassage::updateOrCreate(
                    ['bible_id' => $bibleId, 'normalized_reference' => $normalizedReference],
                    [
                        'api_passage_id' => $result->passageId,
                        'display_reference' => $result->displayReference,
                        'html_content' => $sanitizedHtml,
                        'copyright' => $result->copyright,
                        'fums_token' => $result->fumsToken,
                        'fetched_at' => now(),
                    ]
                );

                $sermon->update(['scripture_passage_id' => $passage->id]);
                $this->line("    resolved: {$normalizedReference}");
                Log::info('sermons:enrich-scripture passage resolved', [
                    'sermon_id' => $sermon->id,
                    'reference' => $normalizedReference,
                    'result_category' => 'resolved',
                ]);
                $counts['resolved']++;
            } catch (\RuntimeException $e) {
                $this->error("    rate_limited/server_error: {$normalizedReference} — {$e->getMessage()}");
                Log::warning('sermons:enrich-scripture rate-limited or server error', [
                    'sermon_id' => $sermon->id,
                    'reference' => $normalizedReference,
                    'error' => $e->getMessage(),
                    'result_category' => 'rate_limited',
                ]);
                $counts['rate_limited']++;
            } catch (\Throwable $e) {
                $this->error("    failed: {$normalizedReference} — {$e->getMessage()}");
                Log::error('sermons:enrich-scripture failed for sermon', [
                    'sermon_id' => $sermon->id,
                    'reference' => $normalizedReference,
                    'error' => $e->getMessage(),
                    'result_category' => 'failed',
                ]);
                $counts['failed']++;
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        if ($useQueue) {
            $this->info("Done. Queued: {$counts['queued']}");
        } else {
            $this->info(sprintf(
                'Done. Resolved: %d, Not found: %d, Unparseable: %d, Rate-limited: %d, Failed: %d',
                $counts['resolved'],
                $counts['not_found'],
                $counts['unparseable'],
                $counts['rate_limited'],
                $counts['failed'],
            ));
        }

        return self::SUCCESS;
    }
}
