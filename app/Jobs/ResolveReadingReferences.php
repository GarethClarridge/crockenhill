<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\ServiceSectionMetadata;
use App\Enums\MediaType;
use App\Enums\ProcessingStep;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\ReadingReferenceExtractor;
use App\Services\Scripture\ScriptureReferenceResolver;
use App\Support\ChurchServiceProcessingTimeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolves the actual scripture reference of each detected bible-reading section from its
 * recited transcript, supplementing or overriding the generic "Bible Reading" title the
 * sparse slide-deck OoS carries. Runs after OoS alignment (so the OoS title is available as a
 * fallback) and before sermon extraction.
 *
 * This is a non-fatal enrichment step: a failed model call or an unparseable result leaves the
 * OoS fallback in place and never fails the run.
 */
class ResolveReadingReferences extends ProcessingJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /**
     * Review flags this job owns and recalculates on every run. Cleared up-front and only
     * re-added when still applicable, so the review state can be downgraded idempotently.
     *
     * @var list<string>
     */
    private const OWNED_REVIEW_FLAGS = [
        'reading_reference_conflict',
        'reading_reference_missing',
    ];

    /**
     * Lower-cased benediction formulae. Several are themselves scripture (e.g. 2 Corinthians
     * 13:14, Numbers 6:24-26), so a phrase match only suppresses a reading when combined with a
     * short duration and an end-of-service position — see {@see self::looksLikeClosingBenediction()}.
     *
     * @var list<string>
     */
    private const BENEDICTION_PHRASES = [
        'the grace of our lord jesus christ',
        'the grace of the lord jesus christ',
        'now to him who is able',
        'now unto him who is able',
        'now unto him that is able',
        'the lord bless you and keep you',
        'the peace of god which passes all understanding',
        'the peace of god, which passeth all understanding',
    ];

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {
        $this->onQueue((string) config('media-processing.queues.audio', 'audio-processing'));
    }

    public function handle(ReadingReferenceExtractor $extractor, ScriptureReferenceResolver $resolver): void
    {
        if ($this->refreshAndCheckCancellation($this->processingLog)) {
            return;
        }

        if ($this->processingLog->processing_type !== MediaType::Livestream) {
            $this->logStepSkipped(ChurchServiceProcessingTimeline::RESOLVE_READING_REFERENCES, 'Reading reference resolution only runs for active livestream processing');

            return;
        }

        if (! config('media-processing.reading_references.enabled', true)) {
            $this->logStepSkipped(ChurchServiceProcessingTimeline::RESOLVE_READING_REFERENCES, 'Reading reference resolution disabled by configuration');

            return;
        }

        $this->markProcessingRunAsProcessing($this->processingLog, ProcessingStep::ResolveReadingReferences->value);
        $this->logStepStart(ChurchServiceProcessingTimeline::RESOLVE_READING_REFERENCES);

        /** @var EloquentCollection<int, ServiceSection> $readingSections */
        $readingSections = ServiceSection::query()
            ->where('media_processing_log_id', $this->processingLog->id)
            ->where('section_type', ServiceSectionType::BibleReading->value)
            ->orderBy('section_order')
            ->orderBy('id')
            ->get();

        $resolvedCount = 0;

        foreach ($readingSections as $section) {
            if ($this->resolveSection($section, $extractor, $resolver)) {
                $resolvedCount++;
            }
        }

        $this->logStepComplete(
            ChurchServiceProcessingTimeline::RESOLVE_READING_REFERENCES,
            sprintf('Resolved %d reading reference(s) from transcript', $resolvedCount)
        );

        Log::info('ResolveReadingReferences completed', [
            'processing_id' => $this->processingLog->processing_id,
            'reading_sections' => $readingSections->count(),
            'resolved' => $resolvedCount,
        ]);
    }

    protected function onJobFailure(Throwable $exception): void
    {
        $this->initializeStepLogging($this->processingLog->processing_id);
        $this->logStepFailed(
            ChurchServiceProcessingTimeline::RESOLVE_READING_REFERENCES,
            $exception->getMessage()
        );
    }

    private function resolveSection(
        ServiceSection $section,
        ReadingReferenceExtractor $extractor,
        ScriptureReferenceResolver $resolver
    ): bool {
        $sectionMetadata = $section->metadata;
        $transcript = $sectionMetadata?->transcript;

        if (! $sectionMetadata instanceof ServiceSectionMetadata || ! is_string($transcript) || trim($transcript) === '') {
            return false;
        }

        $metadata = $sectionMetadata->toArray();
        $oosReference = is_string($metadata['reading_reference'] ?? null) ? $metadata['reading_reference'] : null;
        // normalizeAll keeps every passage of a multi-part reference; the
        // single-passage normalize() would silently drop later subranges.
        $oosCanonical = $oosReference !== null ? $resolver->normalizeAll($oosReference) : null;

        // This job owns these flags; clear them up-front so a rerun re-derives them idempotently
        // and the review state can be downgraded when the cause no longer applies.
        $reviewFlags = $this->withoutOwnedFlags($metadata['review_flags'] ?? null);
        unset($metadata['reading_reference_suppressed']);

        try {
            $result = $extractor->extract($transcript);
        } catch (Throwable $exception) {
            Log::warning('ResolveReadingReferences: extractor failed; keeping OoS fallback', [
                'processing_id' => $this->processingLog->processing_id,
                'section_id' => $section->id,
                'error' => $exception->getMessage(),
            ]);

            // Leave review state untouched on a transient model failure.
            return false;
        }

        $resolved = $result['source'] === 'transcript_ai' && $result['reference'] !== null;

        // A benediction formula at the very end of the service is not a reading, even when the
        // model confidently names a passage (F12). The OoS fallback is left in place.
        if ($resolved && $this->looksLikeClosingBenediction($section, $transcript)) {
            $metadata['reading_reference_source'] = 'none';
            $metadata['reading_reference_raw'] = $result['raw'];
            $metadata['reading_reference_suppressed'] = 'closing_benediction';
            $this->persist($section, $metadata, $reviewFlags);

            return false;
        }

        if (! $resolved) {
            $metadata['reading_reference_source'] = 'none';
            $metadata['reading_reference_raw'] = $result['raw'];

            // No transcript reference and no usable OoS hint: surface the gap so an operator can
            // fill it in, rather than silently omitting the annotation (F17).
            if ($oosCanonical === null) {
                $reviewFlags[] = 'reading_reference_missing';
            }

            $this->persist($section, $metadata, $reviewFlags);

            return false;
        }

        $transcriptReference = $result['reference'];

        $metadata['reading_reference'] = $transcriptReference;
        $metadata['reading_reference_source'] = 'transcript_ai';
        $metadata['reading_reference_confidence'] = $result['confidence'];
        $metadata['reading_reference_raw'] = $result['raw'];

        // Only a parseable OoS reference that genuinely disagrees is a conflict — the generic
        // "Bible Reading" title does not parse and so never flags. A transcript reference that
        // subdivides the planned passage ("Luke 18:31-33, 35-43" vs "Luke 18:31-43") reads the
        // same text and is agreement, not conflict.
        if ($oosCanonical !== null && ! $resolver->referencesAgree($oosCanonical, $transcriptReference)) {
            $reviewFlags[] = 'reading_reference_conflict';
        }

        $this->persist($section, $metadata, $reviewFlags);

        return true;
    }

    /**
     * A short closing section whose transcript is a benediction formula is treated as a
     * benediction, not a reading. Combined evidence (phrase + brevity + end-of-service position)
     * avoids suppressing a benediction passage genuinely read aloud mid-service.
     */
    private function looksLikeClosingBenediction(ServiceSection $section, string $transcript): bool
    {
        if (! $this->matchesBenedictionPhrase($transcript)) {
            return false;
        }

        $maxDuration = (float) config('media-processing.reading_references.benediction_max_duration_seconds', 60);
        if ((float) $section->duration > $maxDuration) {
            return false;
        }

        $maxOrder = (int) ServiceSection::query()
            ->where('media_processing_log_id', $this->processingLog->id)
            ->max('section_order');

        return (int) $section->section_order >= $maxOrder - 1;
    }

    private function matchesBenedictionPhrase(string $transcript): bool
    {
        $normalised = mb_strtolower($transcript);

        foreach (self::BENEDICTION_PHRASES as $phrase) {
            if (str_contains($normalised, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $reviewFlags
     */
    private function persist(ServiceSection $section, array $metadata, array $reviewFlags): void
    {
        $reviewFlags = array_values(array_unique($reviewFlags));

        if ($reviewFlags === []) {
            unset($metadata['review_flags']);
        } else {
            $metadata['review_flags'] = $reviewFlags;
        }

        // Own this section's review state: it needs review when any flag remains (ours or
        // another owner's) or another concern has recorded a review reason. This downgrades the
        // flag we set on a previous run once its cause is gone, without erasing unrelated reasons.
        $section->needs_manual_review = $reviewFlags !== [] || isset($metadata['review_reason']);

        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        $section->save();
    }

    /**
     * @param  mixed  $flags
     * @return list<string>
     */
    private function withoutOwnedFlags($flags): array
    {
        if (! is_array($flags)) {
            return [];
        }

        return array_values(array_filter(
            $flags,
            static fn (mixed $existing): bool => is_string($existing) && ! in_array($existing, self::OWNED_REVIEW_FLAGS, true)
        ));
    }
}
