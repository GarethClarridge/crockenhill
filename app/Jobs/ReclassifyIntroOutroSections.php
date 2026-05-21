<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\ServiceSectionMetadata;
use App\Enums\ProcessingStep;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Support\ChurchServiceProcessingTimeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReclassifyIntroOutroSections extends ProcessingJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {
        $this->onQueue((string) config('media-processing.queues.audio', 'audio-processing'));
    }

    public function handle(): void
    {
        if ($this->refreshAndCheckCancellation($this->processingLog)) {
            return;
        }

        if (! $this->processingLog->usesSegmentationPipeline()) {
            $this->logStepSkipped(ChurchServiceProcessingTimeline::RECLASSIFY_INTRO_OUTRO, 'Intro/outro reclassification only runs for active segmentation processing');

            return;
        }

        $totalDuration = $this->processingLog->duration;
        if ($totalDuration === null || $totalDuration <= 0.0) {
            $this->logStepSkipped(ChurchServiceProcessingTimeline::RECLASSIFY_INTRO_OUTRO, 'No recording duration available for intro/outro detection');

            return;
        }

        $this->markProcessingRunAsProcessing($this->processingLog, ProcessingStep::ReclassifyIntroOutro->value);
        $this->logStepStart(ChurchServiceProcessingTimeline::RECLASSIFY_INTRO_OUTRO);

        /** @var EloquentCollection<int, ServiceSection> $songSections */
        $songSections = ServiceSection::query()
            ->where('media_processing_log_id', $this->processingLog->id)
            ->where('section_type', ServiceSectionType::SONG->value)
            ->orderBy('section_order')
            ->orderBy('id')
            ->get();

        $reclassifiedCount = 0;

        $reclassifiedCount += $this->reclassifyIntro($songSections, $totalDuration);
        $reclassifiedCount += $this->reclassifyOutro($songSections, $totalDuration);

        $this->logStepComplete(
            ChurchServiceProcessingTimeline::RECLASSIFY_INTRO_OUTRO,
            sprintf('Reclassified %d intro/outro section(s)', $reclassifiedCount)
        );

        Log::info('ReclassifyIntroOutroSections completed', [
            'processing_id' => $this->processingLog->processing_id,
            'reclassified' => $reclassifiedCount,
        ]);
    }

    protected function onJobFailure(\Throwable $exception): void
    {
        $this->initializeStepLogging($this->processingLog->processing_id);
        $this->logStepFailed(
            ChurchServiceProcessingTimeline::RECLASSIFY_INTRO_OUTRO,
            $exception->getMessage()
        );
    }

    /**
     * Reclassify the first song section as 'other' if it starts within the intro window
     * and was never matched to a song in the catalog.
     *
     * @param  EloquentCollection<int, ServiceSection>  $songSections
     */
    private function reclassifyIntro(EloquentCollection $songSections, float $totalDuration): int
    {
        $introMaxStart = (float) config('media-processing.section_classification.intro_max_start_seconds', 120);

        $first = $songSections->first();
        if (! $first instanceof ServiceSection) {
            return 0;
        }

        if ((float) $first->start_time > $introMaxStart) {
            return 0;
        }

        if ($this->isMatched($first)) {
            return 0;
        }

        $this->reclassifySection($first, ServiceSectionType::OTHER, 'possible_musical_intro');

        Log::info('ReclassifyIntroOutroSections: reclassified intro section', [
            'processing_id' => $this->processingLog->processing_id,
            'section_id' => $first->id,
            'start_time' => $first->start_time,
        ]);

        return 1;
    }

    /**
     * Reclassify the last song section as 'other' if it ends within the outro window,
     * is short enough to be an outro bumper, and was never matched to a song in the catalog.
     *
     * @param  EloquentCollection<int, ServiceSection>  $songSections
     */
    private function reclassifyOutro(EloquentCollection $songSections, float $totalDuration): int
    {
        $outroMinRemaining = (float) config('media-processing.section_classification.outro_min_remaining_seconds', 30);
        $outroMaxDuration = (float) config('media-processing.section_classification.outro_max_duration_seconds', 60);

        $last = $songSections->last();
        if (! $last instanceof ServiceSection) {
            return 0;
        }

        $remainingAfterSection = $totalDuration - (float) $last->end_time;
        if ($remainingAfterSection > $outroMinRemaining) {
            return 0;
        }

        if ((float) $last->duration > $outroMaxDuration) {
            return 0;
        }

        if ($this->isMatched($last)) {
            return 0;
        }

        $this->reclassifySection($last, ServiceSectionType::OTHER, 'possible_musical_outro');

        Log::info('ReclassifyIntroOutroSections: reclassified outro section', [
            'processing_id' => $this->processingLog->processing_id,
            'section_id' => $last->id,
            'end_time' => $last->end_time,
            'duration' => $last->duration,
        ]);

        return 1;
    }

    private function isMatched(ServiceSection $section): bool
    {
        return $section->song_match_type !== null
            && $section->song_match_type !== ServiceSectionSongMatchType::UNMATCHED;
    }

    private function reclassifySection(ServiceSection $section, ServiceSectionType $newType, string $reviewReason): void
    {
        $metadata = $section->metadata?->toArray() ?? [];
        $metadata['review_reason'] = $reviewReason;
        $metadata['original_section_type'] = ServiceSectionType::SONG->value;

        $section->section_type = $newType;
        $section->needs_manual_review = true;
        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        $section->save();
    }
}
