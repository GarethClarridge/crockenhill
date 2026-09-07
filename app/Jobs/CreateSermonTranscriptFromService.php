<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\SermonEvidenceCoverage;
use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\Media\Audio\ServiceTranscriptReader;
use App\Services\Media\Audio\TranscriptStorageService;
use App\Support\SectionReviewFlagPolicy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateSermonTranscriptFromService extends ProcessingJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * A sermon whose delivered span is materially blind
     * {@see SermonEvidenceCoverage::REVIEW_FRACTION}.
     *
     * Deliberately not a {@see ServiceStructureValidator} flag: those describe
     * the detector's confidence in a boundary, and are re-derived from the
     * banked structure. This one describes the recording behind the boundary,
     * is raised only once the extraction plan exists, and must persist until the
     * evidence itself is recovered.
     */
    public const FLAG_EVIDENCE_INCOMPLETE = 'sermon_evidence_incomplete';

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        private MediaProcessingLog $processingLog,
    ) {}

    public function handle(
        TranscriptStorageService $transcriptStorage,
        ServiceTranscriptReader $serviceTranscripts,
    ): void {
        if ($this->refreshAndCheckCancellation($this->processingLog, $this->job ?? null, $this->attempts())) {
            return;
        }

        $this->logStepStart('creating_sermon_transcript');
        $this->updateProcessingRunStep($this->processingLog, 'creating_sermon_transcript');

        $sermon = $this->processingLog->sermon;
        if (! $sermon instanceof Sermon) {
            throw new \RuntimeException("No sermon found for processing log: {$this->processingLog->processing_id}");
        }

        $transcript = $serviceTranscripts->read($this->processingLog);
        $spans = $this->extractedSpans();
        $sermonText = trim($transcript->sliceTextForSpans($spans));

        if ($sermonText === '') {
            throw new \RuntimeException('The full-service transcript contains no sermon text for the extracted bounds.');
        }

        $coverage = SermonEvidenceCoverage::measure($spans, $transcript);

        if ($coverage->insufficientForAnalysis()) {
            throw new \RuntimeException(sprintf(
                'The extracted sermon span has no transcript evidence for %.1f%% of its %.0f seconds.',
                $coverage->unobservableFraction() * 100,
                $coverage->spanSeconds,
            ));
        }

        $transcriptPath = $transcriptStorage->storeTranscript($sermon->id, $sermonText);

        $this->processingLog->update(['transcript_file_path' => $transcriptPath]);
        $sermon->update(['transcript_file_path' => $transcriptPath]);

        $message = 'Created sermon transcript from full-service transcript';

        if ($coverage->warrantsReview()) {
            $this->flagIncompleteEvidence($coverage);
            $message .= sprintf(
                '; %.1f%% of its %.0f-second span has no transcript evidence',
                $coverage->unobservableFraction() * 100,
                $coverage->spanSeconds,
            );
        }

        $this->updateProcessingRunStep($this->processingLog, 'transcription_completed');
        $this->logStepComplete('creating_sermon_transcript', $message);
    }

    protected function onJobFailure(\Throwable $exception): void
    {
        $this->initializeStepLogging($this->processingLog->processing_id);
        $this->logStepFailed('creating_sermon_transcript', $exception->getMessage());
    }

    /**
     * Ask a reviewer to look at a sermon whose span is materially blind.
     *
     * The flag goes on the section rather than the run because that is where a
     * reviewer meets the sermon, and it survives
     * {@see \App\Services\ChurchService\SectionStructureFlagRederiver}, which
     * re-derives only {@see ServiceStructureValidator::REANNOTATED_FLAGS} and
     * retains everything else. That matters: an evidence flag a later recompute
     * could quietly withdraw would repeat the defect it exists to catch.
     */
    private function flagIncompleteEvidence(SermonEvidenceCoverage $coverage): void
    {
        $section = $this->processingLog->serviceSections()
            ->where('section_type', ServiceSectionType::Sermon)
            ->orderBy('start_time')
            ->first();

        if (! $section instanceof ServiceSection) {
            return;
        }

        $metadata = $section->metadata?->toArray() ?? [];
        $reviewFlags = array_values(array_filter(
            is_array($metadata['review_flags'] ?? null) ? $metadata['review_flags'] : [],
            'is_string',
        ));
        $reviewFlags[] = self::FLAG_EVIDENCE_INCOMPLETE;

        $metadata['review_flags'] = array_values(array_unique($reviewFlags));
        $metadata['sermon_evidence_unobservable_fraction'] = round($coverage->unobservableFraction(), 4);
        $metadata['sermon_evidence_unobservable_seconds'] = round($coverage->unobservableSeconds, 2);

        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        $section->needs_manual_review = SectionReviewFlagPolicy::requiresManualReview(
            $section->section_type,
            $metadata['review_flags'],
            is_string($metadata['sermon_reference'] ?? null) ? $metadata['sermon_reference'] : null,
        );
        $section->save();
    }

    /**
     * The source spans the sermon media was cut from, so the transcript
     * describes the recording a listener actually gets.
     *
     * A concatenated extraction joins the preached reading to the sermon and
     * drops the hymn or notices between them; slicing the outer bounds instead
     * banks that intervening material as sermon text and passes it to the
     * analysis that follows. Runs with no recorded plan — older runs, and any
     * whose plan holds no usable span — keep the recorded bounds.
     *
     * @return list<array{start: float, end: float}>
     */
    private function extractedSpans(): array
    {
        $spans = $this->processingLog->recordedSermonExtractionSpans();

        if ($spans !== null) {
            return $spans;
        }

        return [['start' => $this->sermonStartTime(), 'end' => $this->sermonEndTime()]];
    }

    private function sermonStartTime(): float
    {
        if ($this->processingLog->sermon_start_time === null) {
            throw new \RuntimeException('No sermon start time recorded for this run.');
        }

        return (float) $this->processingLog->sermon_start_time;
    }

    private function sermonEndTime(): float
    {
        if ($this->processingLog->sermon_end_time === null) {
            throw new \RuntimeException('No sermon end time recorded for this run.');
        }

        return (float) $this->processingLog->sermon_end_time;
    }
}
