<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosSemanticAnnotationResult;
use App\Models\InboundEmail;
use App\Support\CanonicalJson;
use Illuminate\Support\Arr;
use RuntimeException;
use Throwable;

/**
 * Recovers `ignored_lines` for archive sources parsed before the field was persisted, from the
 * model annotations the parse cache already holds. Makes no model calls.
 *
 * **Why a backfill exists at all.** `archive_parse_cache.raw_result` is not raw model output
 * despite the name — it is an {@see InboundEmailImportService::encodeParseResult()} payload, and
 * the corpus was banked before that encoding carried `ignored_lines`. So replaying the cache
 * reproduces the parse faithfully *including its omission*, and every exported assertion bundle
 * entry ships `"ignored_lines": []`. `OosEmailExtractionValidator` then reports every greeting and
 * signature as an unclassified source line, which reads as a defect in the document rather than in
 * the transport.
 *
 * **Why this is a replay and not a repair.** The model's own output is never rewritten. Every
 * annotation, patch, telemetry and usage field in the attempt is left byte-identical; the one
 * field written is *derived* from those annotations by {@see OosSemanticIgnoredLines} — the same
 * object the compiler itself uses, so this cannot drift from what a fresh parse would produce.
 * Anyone holding the same annotations and the same rule reproduces the result exactly.
 *
 * **Fail-closed, per source.** A source whose cache offers no usable annotation is skipped and
 * named, never guessed at: inferring "ignored = every line no item claimed" would satisfy the
 * validator's coverage rule without any evidence that the model ever saw those lines, which is the
 * one outcome worse than the refusal it replaces. A source that already carries ignored lines is
 * left alone, so the command is idempotent.
 *
 * Deleted with the rest of the historic import surface at IC8 closeout.
 */
class BackfillOosArchiveIgnoredLines
{
    public function __construct(
        private readonly OosSemanticIgnoredLines $ignoredLines = new OosSemanticIgnoredLines,
    ) {}

    /**
     * @return array{examined:int,backfilled:int,already_present:int,skipped:list<array{message_id:string,reason:string}>,lines:int}
     */
    public function backfill(bool $apply): array
    {
        $examined = 0;
        $backfilled = 0;
        $alreadyPresent = 0;
        $lines = 0;
        $skipped = [];

        InboundEmail::query()
            ->whereNotNull('processing_metadata')
            ->orderBy('id')
            ->each(function (InboundEmail $email) use (
                $apply,
                &$examined,
                &$backfilled,
                &$alreadyPresent,
                &$lines,
                &$skipped,
            ): void {
                $metadata = is_array($email->processing_metadata) ? $email->processing_metadata : [];
                $cache = Arr::get($metadata, OosArchiveParseCacheBinding::MetadataKey);

                if (! is_array($cache) || ! is_array($cache['raw_result'] ?? null)) {
                    return;
                }

                $examined++;

                if (Arr::get($metadata, 'parsing.ignored_lines') !== null
                    && Arr::get($metadata, OosArchiveParseCacheBinding::MetadataKey.'.raw_result.ignored_lines') !== null) {
                    $alreadyPresent++;

                    return;
                }

                try {
                    $recovered = $this->ignoredLinesFor($cache['raw_result']);
                } catch (Throwable $exception) {
                    $skipped[] = [
                        'message_id' => (string) $email->message_id,
                        'reason' => $exception->getMessage(),
                    ];

                    return;
                }

                $backfilled++;
                $lines += count($recovered);

                if (! $apply) {
                    return;
                }

                $this->write($email, $metadata, $recovered);
            });

        return [
            'examined' => $examined,
            'backfilled' => $backfilled,
            'already_present' => $alreadyPresent,
            'skipped' => $skipped,
            'lines' => $lines,
        ];
    }

    /**
     * The parse's own selected attempt, never merely the first. A repaired parse banks the
     * pre-repair attempt alongside the one it kept, and compiling the wrong one would write line
     * dispositions the parse never acted on.
     *
     * @param  array<string, mixed>  $rawResult
     * @return list<array{line_id:int,reason:string}>
     */
    private function ignoredLinesFor(array $rawResult): array
    {
        $attempts = $rawResult['extraction_attempts'] ?? null;

        if (! is_array($attempts) || $attempts === []) {
            throw new RuntimeException('the cached parse carries no extraction attempt');
        }

        $selected = array_values(array_filter(
            $attempts,
            static fn (mixed $attempt): bool => is_array($attempt) && ($attempt['selected'] ?? false) === true,
        ));

        if (count($selected) !== 1) {
            throw new RuntimeException(sprintf(
                'the cached parse has %d selected attempts, so which annotation it acted on is ambiguous',
                count($selected),
            ));
        }

        $annotations = $selected[0]['final_annotations'] ?? null;

        if (! is_array($annotations)) {
            throw new RuntimeException('the selected attempt stores no final annotations');
        }

        return $this->ignoredLines->forResult(OosSemanticAnnotationResult::fromArray($annotations));
    }

    /**
     * Written to both halves deliberately. `parsing` is what the assertion bundle exports and what
     * the review inbox reads; `raw_result` is what the next `--cache-only` replay decodes, and
     * leaving it stale would silently undo this backfill on the next run.
     *
     * @param  array<string, mixed>  $metadata
     * @param  list<array{line_id:int,reason:string}>  $ignoredLines
     */
    private function write(InboundEmail $email, array $metadata, array $ignoredLines): void
    {
        Arr::set($metadata, 'parsing.ignored_lines', $ignoredLines);

        $cacheKey = OosArchiveParseCacheBinding::MetadataKey;
        Arr::set($metadata, "{$cacheKey}.raw_result.ignored_lines", $ignoredLines);

        /**
         * Recomputed rather than left alone: `raw_result_hash` is the record of what the cached
         * payload *is*, and a hash that no longer describes its own payload is worse than no hash,
         * because it verifies nothing while looking like it does.
         */
        Arr::set($metadata, "{$cacheKey}.raw_result_hash", CanonicalJson::hash(
            Arr::get($metadata, "{$cacheKey}.raw_result"),
        ));
        Arr::set($metadata, "{$cacheKey}.ignored_lines_backfilled_at", now()->toIso8601String());

        $email->processing_metadata = $metadata;
        $email->save();
    }
}
