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

        // Clear any prior conflict flag so a rerun re-derives it idempotently.
        $reviewFlags = $this->withoutFlag($metadata['review_flags'] ?? null, 'reading_reference_conflict');

        try {
            $result = $extractor->extract($transcript);
        } catch (Throwable $exception) {
            Log::warning('ResolveReadingReferences: extractor failed; keeping OoS fallback', [
                'processing_id' => $this->processingLog->processing_id,
                'section_id' => $section->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        if ($result['source'] !== 'transcript_ai' || $result['reference'] === null) {
            $metadata['reading_reference_source'] = 'none';
            $metadata['reading_reference_raw'] = $result['raw'];
            $this->persist($section, $metadata, $reviewFlags);

            return false;
        }

        $transcriptReference = $result['reference'];

        $metadata['reading_reference'] = $transcriptReference;
        $metadata['reading_reference_source'] = 'transcript_ai';
        $metadata['reading_reference_confidence'] = $result['confidence'];
        $metadata['reading_reference_raw'] = $result['raw'];

        // Only a parseable OoS reference that genuinely disagrees is a conflict — the generic
        // "Bible Reading" title does not parse and so never flags.
        $oosCanonical = $oosReference !== null ? $resolver->normalize($oosReference) : null;
        $conflict = $oosCanonical !== null && $oosCanonical !== $transcriptReference;

        if ($conflict) {
            $reviewFlags[] = 'reading_reference_conflict';
            $section->needs_manual_review = true;
        }

        $this->persist($section, $metadata, $reviewFlags);

        return true;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $reviewFlags
     */
    private function persist(ServiceSection $section, array $metadata, array $reviewFlags): void
    {
        if ($reviewFlags === []) {
            unset($metadata['review_flags']);
        } else {
            $metadata['review_flags'] = array_values(array_unique($reviewFlags));
        }

        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        $section->save();
    }

    /**
     * @param  mixed  $flags
     * @return list<string>
     */
    private function withoutFlag($flags, string $flag): array
    {
        if (! is_array($flags)) {
            return [];
        }

        return array_values(array_filter(
            $flags,
            static fn (mixed $existing): bool => is_string($existing) && $existing !== $flag
        ));
    }
}
