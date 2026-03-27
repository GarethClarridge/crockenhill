<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\MediaType;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ServiceSectionSyncService;
use App\Services\SpeechSectionClassificationService;
use App\Support\ChurchServiceProcessingTimeline;
use App\Support\ServiceSectionConfidence;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ClassifySpeechSections extends ProcessingJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 1200;

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {
        $this->onQueue((string) config('media-processing.queues.audio', 'audio-processing'));
    }

    public function handle(
        SpeechSectionClassificationService $classificationService,
        ServiceSectionSyncService $syncService
    ): void {
        $processingLog = $this->processingLog->fresh();
        if (! $processingLog instanceof MediaProcessingLog) {
            return;
        }

        $this->processingLog = $processingLog;
        $this->initializeStepLogging($this->processingLog->processing_id);

        if (
            $this->processingLog->processing_type !== MediaType::Livestream
            || $this->processingLog->isCancelled()
        ) {
            $this->logStepSkipped(ChurchServiceProcessingTimeline::CLASSIFY_SPEECH_SECTIONS, 'Speech section classification only runs for active livestream processing');

            return;
        }

        if (! (bool) config('media-processing.section_classification.classify_speech_sections', true)) {
            $this->logStepSkipped(ChurchServiceProcessingTimeline::CLASSIFY_SPEECH_SECTIONS, 'Speech section classification disabled');

            return;
        }

        $this->logStepStart(ChurchServiceProcessingTimeline::CLASSIFY_SPEECH_SECTIONS);

        $existingSections = ServiceSection::query()
            ->where('media_processing_log_id', $this->processingLog->id)
            ->orderBy('section_order')
            ->orderBy('id')
            ->get();

        if ($existingSections->isEmpty()) {
            $this->logStepSkipped(ChurchServiceProcessingTimeline::CLASSIFY_SPEECH_SECTIONS, 'No sections available for transcript classification');

            return;
        }

        $rewrittenSections = [];

        foreach ($existingSections as $section) {
            if (! $this->shouldClassify($section)) {
                $rewrittenSections[] = $this->payloadFromExistingSection($section);

                continue;
            }

            try {
                $classifiedSections = $classificationService->classify($section);

                foreach ($classifiedSections as $classifiedSection) {
                    $rewrittenSections[] = $this->payloadFromClassifiedSection($section, $classifiedSection);
                }
            } catch (\Throwable $throwable) {
                Log::warning('Failed to classify speech section with AI transcript analysis', [
                    'processing_id' => $this->processingLog->processing_id,
                    'service_section_id' => $section->id,
                    'error' => $throwable->getMessage(),
                ]);

                $rewrittenSections[] = $this->fallbackPayload($section, 'speech_section_classification_failed');
            }
        }

        $rewrittenSections = $this->foldShortSongsIntoSermon($rewrittenSections);

        foreach ($rewrittenSections as $index => &$rewrittenSection) {
            $rewrittenSection['section_order'] = $index + 1;
        }
        unset($rewrittenSection);

        $syncService->sync($this->processingLog, $rewrittenSections);

        $this->logStepComplete(
            ChurchServiceProcessingTimeline::CLASSIFY_SPEECH_SECTIONS,
            sprintf('Rewrote %d section(s) from transcript analysis', count($rewrittenSections))
        );
    }

    public function failed(\Throwable $exception): void
    {
        $this->initializeStepLogging($this->processingLog->processing_id);
        $this->logStepFailed(
            ChurchServiceProcessingTimeline::CLASSIFY_SPEECH_SECTIONS,
            $exception->getMessage()
        );

        Log::error('ClassifySpeechSections job failed permanently', [
            'processing_id' => $this->processingLog->processing_id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }

    private function shouldClassify(ServiceSection $section): bool
    {
        if ($section->status !== ServiceSectionStatus::IDENTIFIED) {
            return false;
        }

        if (in_array($section->section_type, [ServiceSectionType::SONG, ServiceSectionType::SERMON], true)) {
            return false;
        }

        $transcript = $section->metadata['transcript'] ?? null;

        return is_string($transcript) && trim($transcript) !== '';
    }

    /**
     * @return array{
     *     church_service_item_id: int|null,
     *     section_type: string,
     *     section_order: int,
     *     title: ?string,
     *     start_time: float,
     *     end_time: float,
     *     duration: float,
     *     confidence: float,
     *     status: string,
     *     needs_manual_review: bool,
     *     source_segment_ids: array<int, int>,
     *     metadata: array<string, mixed>
     * }
     */
    private function payloadFromExistingSection(ServiceSection $section): array
    {
        return [
            'church_service_item_id' => $section->church_service_item_id,
            'section_type' => $section->section_type->value,
            'section_order' => $section->section_order,
            'title' => $section->title,
            'start_time' => (float) $section->start_time,
            'end_time' => (float) $section->end_time,
            'duration' => max(0.0, (float) $section->end_time - (float) $section->start_time),
            'confidence' => ServiceSectionConfidence::resolve($section->confidence, $section->metadata?->toArray() ?? []),
            'status' => $section->status->value,
            'needs_manual_review' => $section->needs_manual_review,
            'source_segment_ids' => $this->normaliseSourceSegmentIds($section->source_segment_ids),
            'metadata' => $section->metadata?->toArray() ?? [],
        ];
    }

    /**
     * @param  array{
     *     section_type: string,
     *     title: ?string,
     *     start_time: float,
     *     end_time: float,
     *     duration: float,
     *     confidence?: float,
     *     needs_manual_review: bool,
     *     metadata: array<string, mixed>
     * }  $classifiedSection
     * @return array{
     *     church_service_item_id: int|null,
     *     section_type: string,
     *     section_order: int,
     *     title: ?string,
     *     start_time: float,
     *     end_time: float,
     *     duration: float,
     *     confidence: float,
     *     status: string,
     *     needs_manual_review: bool,
     *     source_segment_ids: array<int, int>,
     *     metadata: array<string, mixed>
     * }
     */
    private function payloadFromClassifiedSection(ServiceSection $originalSection, array $classifiedSection): array
    {
        $sectionType = ServiceSectionType::from($classifiedSection['section_type']);
        $sameSignatureType = $sectionType === $originalSection->section_type;

        $metadata = array_merge($originalSection->metadata?->toArray() ?? [], $classifiedSection['metadata'], [
            'source_segment_ids' => $this->normaliseSourceSegmentIds($originalSection->source_segment_ids),
            'derived_from_section_type' => $originalSection->section_type->value,
        ]);

        $needsManualReview = $classifiedSection['needs_manual_review'];

        if ($sectionType === ServiceSectionType::SERMON && $originalSection->section_type !== ServiceSectionType::SERMON) {
            $needsManualReview = true;
            $metadata['review_reason'] = 'secondary_sermon_candidate';
        }

        return [
            'church_service_item_id' => $sameSignatureType ? $originalSection->church_service_item_id : null,
            'section_type' => $sectionType->value,
            'section_order' => $originalSection->section_order,
            'title' => $sameSignatureType ? $originalSection->title : null,
            'start_time' => (float) $classifiedSection['start_time'],
            'end_time' => (float) $classifiedSection['end_time'],
            'duration' => max(0.0, (float) $classifiedSection['end_time'] - (float) $classifiedSection['start_time']),
            'confidence' => ServiceSectionConfidence::resolve(
                is_numeric($classifiedSection['confidence'] ?? null) ? (float) $classifiedSection['confidence'] : null,
                $metadata
            ),
            'status' => ServiceSectionStatus::IDENTIFIED->value,
            'needs_manual_review' => $needsManualReview,
            'source_segment_ids' => $this->normaliseSourceSegmentIds($originalSection->source_segment_ids),
            'metadata' => $metadata,
        ];
    }

    /**
     * @return array{
     *     church_service_item_id: int|null,
     *     section_type: string,
     *     section_order: int,
     *     title: ?string,
     *     start_time: float,
     *     end_time: float,
     *     duration: float,
     *     confidence: float,
     *     status: string,
     *     needs_manual_review: bool,
     *     source_segment_ids: array<int, int>,
     *     metadata: array<string, mixed>
     * }
     */
    private function fallbackPayload(ServiceSection $section, string $reason): array
    {
        $payload = $this->payloadFromExistingSection($section);
        $payload['needs_manual_review'] = true;
        $payload['metadata'] = array_merge($payload['metadata'], [
            'confidence_source' => 'ai_transcript',
            'review_reason' => $reason,
        ]);

        return $payload;
    }

    /**
     * @param  array<int, array{
     *     church_service_item_id: int|null,
     *     section_type: string,
     *     section_order: int,
     *     title: ?string,
     *     start_time: float,
     *     end_time: float,
     *     duration: float,
     *     confidence: float,
     *     status: string,
     *     needs_manual_review: bool,
     *     source_segment_ids: array<int, int>,
     *     metadata: array<string, mixed>
     * }>  $sections
     * @return array<int, array{
     *     church_service_item_id: int|null,
     *     section_type: string,
     *     section_order: int,
     *     title: ?string,
     *     start_time: float,
     *     end_time: float,
     *     duration: float,
     *     status: string,
     *     needs_manual_review: bool,
     *     source_segment_ids: array<int, int>,
     *     metadata: array<string, mixed>
     * }>
     */
    private function foldShortSongsIntoSermon(array $sections): array
    {
        $maxSongSeconds = (float) config('media-processing.section_classification.short_song_max_duration_seconds', 90);
        $folded = [];
        $index = 0;
        $count = count($sections);

        while ($index < $count) {
            $current = $sections[$index];

            if ($current['section_type'] === ServiceSectionType::SERMON->value) {
                $merged = $current;
                $scanIndex = $index + 1;
                $foldedSongSections = is_array($merged['metadata']['folded_song_sections'] ?? null)
                    ? $merged['metadata']['folded_song_sections']
                    : [];
                $foldedSongDuration = (float) ($merged['metadata']['folded_song_duration_seconds'] ?? 0.0);
                $mergedAnySong = false;

                while ($scanIndex < $count) {
                    $songCluster = [];
                    $songClusterDuration = 0.0;

                    while (
                        $scanIndex < $count
                        && $sections[$scanIndex]['section_type'] === ServiceSectionType::SONG->value
                        && (float) $sections[$scanIndex]['duration'] < $maxSongSeconds
                    ) {
                        $songCluster[] = $sections[$scanIndex];
                        $songClusterDuration += (float) $sections[$scanIndex]['duration'];
                        $scanIndex++;
                    }

                    if ($songCluster === [] || $scanIndex >= $count) {
                        break;
                    }

                    $followingSermon = $sections[$scanIndex];

                    if ($followingSermon['section_type'] !== ServiceSectionType::SERMON->value) {
                        break;
                    }

                    $mergedAnySong = true;
                    $foldedSongDuration += $songClusterDuration;

                    foreach ($songCluster as $songSection) {
                        $foldedSongSections[] = $songSection['title'] ?? ServiceSectionType::SONG->label();
                    }

                    $merged['end_time'] = (float) $followingSermon['end_time'];
                    $merged['duration'] = max(0.0, (float) $merged['end_time'] - (float) $merged['start_time']);
                    $merged['confidence'] = max((float) $merged['confidence'], (float) $followingSermon['confidence']);
                    $merged['needs_manual_review'] = false;
                    $songClusterSourceSegmentIds = [];

                    foreach ($songCluster as $songSection) {
                        $songClusterSourceSegmentIds = array_merge(
                            $songClusterSourceSegmentIds,
                            $this->normaliseSourceSegmentIds($songSection['source_segment_ids'])
                        );
                    }

                    $merged['source_segment_ids'] = array_values(array_unique(array_merge(
                        $this->normaliseSourceSegmentIds($merged['source_segment_ids']),
                        $songClusterSourceSegmentIds,
                        $this->normaliseSourceSegmentIds($followingSermon['source_segment_ids'])
                    )));

                    $scanIndex++;
                }

                if ($mergedAnySong) {
                    unset($merged['metadata']['review_reason']);

                    $merged['metadata'] = array_merge($merged['metadata'], [
                        'folded_song_sections' => array_values(array_unique($foldedSongSections)),
                        'folded_song_duration_seconds' => $foldedSongDuration,
                    ]);

                    $folded[] = $merged;
                    $index = $scanIndex;

                    continue;
                }
            }

            $folded[] = $current;
            $index++;
        }

        return $folded;
    }

    /**
     * @param  array<int, mixed>|null  $sourceSegmentIds
     * @return array<int, int>
     */
    private function normaliseSourceSegmentIds(?array $sourceSegmentIds): array
    {
        if (! is_array($sourceSegmentIds)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $id): ?int => is_numeric($id) ? (int) $id : null, $sourceSegmentIds),
            static fn (?int $id): bool => $id !== null
        ));
    }
}
