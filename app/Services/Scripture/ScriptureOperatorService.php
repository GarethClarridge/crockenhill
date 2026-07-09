<?php

declare(strict_types=1);

namespace App\Services\Scripture;

use App\Actions\QueueScriptureEnrichment;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\LazyCollection;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-type EnrichmentSummary array{
 *     resolved:int,
 *     updated:int,
 *     not_found:int,
 *     unparseable:int,
 *     rate_limited:int,
 *     failed:int,
 *     queued:int,
 *     budget_exceeded:int
 * }
 * @phpstan-type RefreshSummary array{
 *     updated:int,
 *     not_found:int,
 *     rate_limited:int,
 *     failed:int,
 *     budget_exceeded:int
 * }
 */
class ScriptureOperatorService
{
    public function __construct(
        private readonly ApiBibleClient $client,
        private readonly ScriptureReferenceResolver $resolver,
        private readonly ScriptureHtmlSanitizer $sanitizer,
        private readonly QueueScriptureEnrichment $queueScriptureEnrichment,
    ) {}

    public function countEnrichmentCandidates(int $limit = 100): int
    {
        return Sermon::query()
            ->whereNotNull('reference')
            ->where('reference', '!=', '')
            ->whereNull('scripture_passage_id')
            ->limit($limit)
            ->count();
    }

    public function countRefreshCandidates(): int
    {
        $refreshAfterDays = (int) config('services.api_bible.refresh_after_days', 28);

        return ScripturePassage::query()
            ->where('fetched_at', '<', now()->subDays($refreshAfterDays))
            ->count();
    }

    /**
     * @return array{
     *     sermons: Collection<int, Sermon>,
     *     summary: EnrichmentSummary,
     *     dry_run: bool,
     *     queue: bool,
     *     stopped_early: bool
     * }
     */
    public function runEnrichment(
        int $limit = 100,
        bool $dryRun = false,
        bool $queue = false,
        int $delayMs = 500,
        ?callable $progress = null,
    ): array {
        $sermons = Sermon::query()
            ->whereNotNull('reference')
            ->where('reference', '!=', '')
            ->whereNull('scripture_passage_id')
            ->limit($limit)
            ->get();

        $summary = $this->emptySummary();
        $stoppedEarly = false;

        foreach ($sermons as $index => $sermon) {
            if ($dryRun) {
                if ($progress !== null) {
                    $progress('dry-run', $sermon, (string) $sermon->reference);
                }

                continue;
            }

            if ($queue) {
                $this->queueScriptureEnrichment->dispatch($sermon);
                $summary['queued']++;
                if ($progress !== null) {
                    $progress('queued', $sermon, (string) $sermon->reference);
                }

                continue;
            }

            if (! $this->client->hasDailyBudget()) {
                $summary['budget_exceeded'] += $sermons->count() - $index;
                $stoppedEarly = true;
                if ($progress !== null) {
                    $progress('budget_exceeded', $sermon, (string) $sermon->reference);
                }

                break;
            }

            try {
                $status = $this->enrichSermon($sermon);
                $summary[$status]++;
                if ($progress !== null) {
                    $progress($status, $sermon, (string) $sermon->reference);
                }
            } catch (\RuntimeException $exception) {
                $summary['rate_limited']++;
                if ($progress !== null) {
                    $progress('rate_limited', $sermon, $exception->getMessage());
                }
            } catch (\Throwable $exception) {
                $summary['failed']++;
                if ($progress !== null) {
                    $progress('failed', $sermon, $exception->getMessage());
                }
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        return [
            'sermons' => $sermons,
            'summary' => $summary,
            'dry_run' => $dryRun,
            'queue' => $queue,
            'stopped_early' => $stoppedEarly,
        ];
    }

    /**
     * @return array{
     *     passages: LazyCollection<int, ScripturePassage>,
     *     summary: RefreshSummary,
     *     dry_run: bool,
     *     stopped_early: bool
     * }
     */
    public function runRefresh(bool $dryRun = false, int $delayMs = 500, ?callable $progress = null): array
    {
        $refreshAfterDays = (int) config('services.api_bible.refresh_after_days', 28);
        $query = ScripturePassage::query()
            ->where('fetched_at', '<', now()->subDays($refreshAfterDays));

        /**
         * Performance Optimization: Use lazyById() to iterate through passages one by one,
         * keeping memory usage low for background refresh tasks.
         */
        $totalCandidates = $query->clone()->count();
        $passages = $query->lazyById(100);

        $summary = [
            'updated' => 0,
            'not_found' => 0,
            'rate_limited' => 0,
            'failed' => 0,
            'budget_exceeded' => 0,
        ];
        $stoppedEarly = false;

        foreach ($passages as $index => $passage) {
            if ($dryRun) {
                if ($progress !== null) {
                    $progress('dry-run', $passage, $passage->normalized_reference);
                }

                continue;
            }

            if (! $this->client->hasDailyBudget()) {
                $summary['budget_exceeded'] += $totalCandidates - $index;
                $stoppedEarly = true;
                if ($progress !== null) {
                    $progress('budget_exceeded', $passage, $passage->normalized_reference);
                }

                break;
            }

            try {
                $status = $this->refreshPassage($passage);
                $summary[$status]++;
                if ($progress !== null) {
                    $progress($status, $passage, $passage->normalized_reference);
                }
            } catch (\RuntimeException $exception) {
                $summary['rate_limited']++;
                if ($progress !== null) {
                    $progress('rate_limited', $passage, $exception->getMessage());
                }
            } catch (\Throwable $exception) {
                $summary['failed']++;
                if ($progress !== null) {
                    $progress('failed', $passage, $exception->getMessage());
                }
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        return [
            'passages' => $passages,
            'summary' => $summary,
            'dry_run' => $dryRun,
            'stopped_early' => $stoppedEarly,
        ];
    }

    /**
     * @return 'resolved'|'updated'|'not_found'|'unparseable'|'failed'
     */
    public function enrichSermon(Sermon $sermon): string
    {
        $rawReference = $sermon->reference;

        if (! is_string($rawReference) || trim($rawReference) === '') {
            Log::info('FetchBibleTextForSermon: skipping — no reference', ['sermon_id' => $sermon->id]);

            return 'unparseable';
        }

        $normalizedReference = $this->resolver->normalize($rawReference);

        if ($normalizedReference === null) {
            Log::info('FetchBibleTextForSermon: skipping — unparseable reference', [
                'sermon_id' => $sermon->id,
                'reference' => $rawReference,
            ]);

            return 'unparseable';
        }

        $bibleId = (string) config('services.api_bible.default_bible_id');
        $existing = ScripturePassage::query()
            ->where('bible_id', $bibleId)
            ->where('normalized_reference', $normalizedReference)
            ->first();

        if ($existing instanceof ScripturePassage && ! $existing->isStale()) {
            $sermon->update(['scripture_passage_id' => $existing->id]);

            Log::info('FetchBibleTextForSermon: linked cached passage', [
                'sermon_id' => $sermon->id,
                'passage_id' => $existing->id,
            ]);

            return 'resolved';
        }

        $result = $existing instanceof ScripturePassage && is_string($existing->api_passage_id) && $existing->api_passage_id !== ''
            ? $this->client->fetchPassageById($existing->api_passage_id)
            : $this->client->searchPassage($normalizedReference);

        if ($result === null) {
            Log::info('FetchBibleTextForSermon: passage not found (terminal — not retrying)', [
                'sermon_id' => $sermon->id,
                'reference' => $normalizedReference,
                'result_category' => 'not_found',
            ]);

            return 'not_found';
        }

        $sanitizedHtml = $this->sanitizer->sanitize($result->htmlContent);

        if ($sanitizedHtml === null) {
            Log::warning('FetchBibleTextForSermon: sanitized HTML was empty', [
                'sermon_id' => $sermon->id,
                'reference' => $normalizedReference,
            ]);

            return 'failed';
        }

        $passageData = [
            'bible_id' => $bibleId,
            'normalized_reference' => $normalizedReference,
            'api_passage_id' => $result->passageId,
            'display_reference' => $this->trustedDisplayReference($result->displayReference, $normalizedReference),
            'html_content' => $sanitizedHtml,
            'copyright' => $result->copyright,
            'fums_token' => $result->fumsToken,
            'fetched_at' => now(),
        ];

        try {
            $this->validatePassageData($passageData);
        } catch (ValidationException $e) {
            Log::error('FetchBibleTextForSermon: validation failed', [
                'sermon_id' => $sermon->id,
                'errors' => $e->errors(),
            ]);

            return 'failed';
        }

        $passage = ScripturePassage::query()->updateOrCreate(
            ['bible_id' => $bibleId, 'normalized_reference' => $normalizedReference],
            $passageData
        );

        $sermon->update(['scripture_passage_id' => $passage->id]);

        Log::info('FetchBibleTextForSermon: passage resolved and linked', [
            'sermon_id' => $sermon->id,
            'passage_id' => $passage->id,
            'reference' => $normalizedReference,
        ]);

        return $existing instanceof ScripturePassage ? 'updated' : 'resolved';
    }

    /**
     * @return 'updated'|'not_found'|'failed'
     */
    public function refreshPassage(ScripturePassage $passage): string
    {
        $result = is_string($passage->api_passage_id) && $passage->api_passage_id !== ''
            ? $this->client->fetchPassageById($passage->api_passage_id)
            : $this->client->searchPassage($passage->normalized_reference);

        if ($result === null) {
            Log::info('scripture:refresh-passages passage not found', [
                'passage_id' => $passage->id,
                'reference' => $passage->normalized_reference,
                'result_category' => 'not_found',
            ]);

            return 'not_found';
        }

        $sanitizedHtml = $this->sanitizer->sanitize($result->htmlContent);

        if ($sanitizedHtml === null) {
            Log::warning('scripture:refresh-passages sanitized HTML was empty', [
                'passage_id' => $passage->id,
                'reference' => $passage->normalized_reference,
            ]);

            return 'failed';
        }

        $refreshData = [
            'api_passage_id' => $result->passageId,
            'display_reference' => $this->trustedDisplayReference($result->displayReference, $passage->normalized_reference),
            'html_content' => $sanitizedHtml,
            'copyright' => $result->copyright,
            'fums_token' => $result->fumsToken,
            'fetched_at' => now(),
        ];

        try {
            $this->validatePassageData($refreshData);
        } catch (ValidationException $e) {
            Log::error('scripture:refresh-passages validation failed', [
                'passage_id' => $passage->id,
                'errors' => $e->errors(),
            ]);

            return 'failed';
        }

        $passage->update($refreshData);

        // A changed display form means linked sermons may carry the old one as
        // their reference (SermonIdentitySyncService canonicalises against the
        // passage). Saving each sermon lets the identity observer re-derive it,
        // so a refresh also repairs references stored from a corrupted display.
        if ($passage->wasChanged('display_reference')) {
            Sermon::query()
                ->where('scripture_passage_id', $passage->id)
                ->get()
                ->each(static fn (Sermon $sermon) => $sermon->save());
        }

        Log::info('scripture:refresh-passages passage refreshed', [
            'passage_id' => $passage->id,
            'reference' => $passage->normalized_reference,
            'result_category' => 'updated',
        ]);

        return 'updated';
    }

    /**
     * The display reference to store: api.bible's rendering when it names the
     * same verse span as our normalized reference, otherwise the normalized
     * reference itself. Their renderer drops the chapter colon from
     * cross-chapter ranges ("Joshua 4:1-5:1" comes back as "Joshua 4:1-51"),
     * and readers prefer display_reference, so a corrupted form would surface
     * on sermons verbatim.
     */
    private function trustedDisplayReference(string $displayReference, string $normalizedReference): string
    {
        if ($this->resolver->referencesRenderSameSpan($displayReference, $normalizedReference)) {
            return $displayReference;
        }

        Log::warning('Distrusting api.bible display reference: span differs from normalized reference', [
            'display_reference' => $displayReference,
            'normalized_reference' => $normalizedReference,
        ]);

        return $normalizedReference;
    }

    /**
     * Validate scripture passage data.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function validatePassageData(array $data): void
    {
        Validator::make($data, [
            'bible_id' => ['sometimes', 'required', 'string', 'max:255'],
            'normalized_reference' => ['sometimes', 'required', 'string', 'max:255'],
            'api_passage_id' => ['nullable', 'string', 'max:255'],
            'display_reference' => ['nullable', 'string', 'max:255'],
            'fums_token' => ['nullable', 'string', 'max:255'],
            'html_content' => ['required', 'string'],
            'copyright' => ['required', 'string'],
            'fetched_at' => ['required', 'date'],
        ])->validate();
    }

    /**
     * @return EnrichmentSummary
     */
    private function emptySummary(): array
    {
        return [
            'resolved' => 0,
            'updated' => 0,
            'not_found' => 0,
            'unparseable' => 0,
            'rate_limited' => 0,
            'failed' => 0,
            'queued' => 0,
            'budget_exceeded' => 0,
        ];
    }
}
