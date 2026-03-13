<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Services\ApiBibleBudgetExhaustedException;
use App\Services\ApiBibleClient;
use App\Services\ScriptureHtmlSanitizer;
use App\Services\ScriptureReferenceResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\FailOnException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchBibleTextForSermon implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        private Sermon $sermon,
    ) {}

    public function handle(
        ApiBibleClient $client,
        ScriptureReferenceResolver $resolver,
        ScriptureHtmlSanitizer $sanitizer,
    ): void {
        if (! config('services.api_bible.enabled')) {
            return;
        }

        $rawReference = $this->sermon->reference;

        if (empty($rawReference)) {
            Log::info('FetchBibleTextForSermon: skipping — no reference', ['sermon_id' => $this->sermon->id]);

            return;
        }

        $normalizedReference = $resolver->normalize($rawReference);

        if ($normalizedReference === null) {
            Log::info('FetchBibleTextForSermon: skipping — unparseable reference', [
                'sermon_id' => $this->sermon->id,
                'reference' => $rawReference,
            ]);

            return;
        }

        $bibleId = (string) config('services.api_bible.default_bible_id');

        // Check cache first
        $existing = ScripturePassage::where('bible_id', $bibleId)
            ->where('normalized_reference', $normalizedReference)
            ->first();

        if ($existing && ! $existing->isStale()) {
            // Reuse cached passage — just link the sermon
            $this->sermon->update(['scripture_passage_id' => $existing->id]);

            Log::info('FetchBibleTextForSermon: linked cached passage', [
                'sermon_id' => $this->sermon->id,
                'passage_id' => $existing->id,
            ]);

            return;
        }

        // Fetch from api.bible
        if ($existing && $existing->api_passage_id) {
            // Prefer refresh-by-ID for stale passages to avoid re-searching
            $result = $client->fetchPassageById($existing->api_passage_id);
        } else {
            $result = $client->searchPassage($normalizedReference);
        }

        if ($result === null) {
            Log::info('FetchBibleTextForSermon: passage not found (terminal — not retrying)', [
                'sermon_id' => $this->sermon->id,
                'reference' => $normalizedReference,
                'result_category' => 'not_found',
            ]);

            return;
        }

        $sanitizedHtml = $sanitizer->sanitize($result->htmlContent);

        if ($sanitizedHtml === null) {
            Log::warning('FetchBibleTextForSermon: sanitized HTML was empty', [
                'sermon_id' => $this->sermon->id,
                'reference' => $normalizedReference,
            ]);

            return;
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

        $this->sermon->update(['scripture_passage_id' => $passage->id]);

        Log::info('FetchBibleTextForSermon: passage resolved and linked', [
            'sermon_id' => $this->sermon->id,
            'passage_id' => $passage->id,
            'reference' => $normalizedReference,
        ]);
    }

    /**
     * Budget exhaustion is non-retryable — the daily limit resets at midnight,
     * not in 30 seconds. FailOnException marks the job as permanently failed
     * immediately, bypassing the retry/backoff cycle.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new FailOnException([ApiBibleBudgetExhaustedException::class])];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('FetchBibleTextForSermon: job failed permanently', [
            'sermon_id' => $this->sermon->id,
            'reference' => $this->sermon->reference,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 60, 120];
    }
}
