<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\MediaType;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\OosAlignmentService;
use App\Services\ServiceSectionSyncService;
use App\Services\SpeechSectionClassificationService;
use App\Support\ServiceSectionConfidence;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ClassifySpeechSections implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 1200;

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {}

    public function handle(
        SpeechSectionClassificationService $classificationService,
        ServiceSectionSyncService $syncService,
        OosAlignmentService $alignmentService
    ): void {
        $processingLog = $this->processingLog->fresh();
        if (! $processingLog instanceof MediaProcessingLog) {
            return;
        }

        $this->processingLog = $processingLog;

        if (
            $this->processingLog->processing_type !== MediaType::Livestream
            || $this->processingLog->isCancelled()
        ) {
            return;
        }

        if (! (bool) config('media-processing.section_classification.classify_speech_sections', true)) {
            $alignmentService->alignForProcessingLog($this->processingLog);

            return;
        }

        $existingSections = ServiceSection::query()
            ->where('media_processing_log_id', $this->processingLog->id)
            ->orderBy('section_order')
            ->orderBy('id')
            ->get();

        if ($existingSections->isEmpty()) {
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
        $alignmentService->alignForProcessingLog($this->processingLog);
    }

    public function failed(\Throwable $exception): void
    {
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
            'confidence' => ServiceSectionConfidence::resolve($section->confidence, $section->metadata),
            'status' => $section->status->value,
            'needs_manual_review' => $section->needs_manual_review,
            'source_segment_ids' => $this->normaliseSourceSegmentIds($section->source_segment_ids),
            'metadata' => is_array($section->metadata) ? $section->metadata : [],
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

        $metadata = array_merge($originalSection->metadata ?? [], $classifiedSection['metadata'], [
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
            $next = $sections[$index + 1] ?? null;
            $afterNext = $sections[$index + 2] ?? null;

            if (
                $current['section_type'] === ServiceSectionType::SERMON->value
                && is_array($next)
                && $next['section_type'] === ServiceSectionType::SONG->value
                && (float) $next['duration'] < $maxSongSeconds
                && is_array($afterNext)
                && $afterNext['section_type'] === ServiceSectionType::SERMON->value
            ) {
                $mergedMetadata = array_merge($current['metadata'], [
                    'folded_song_sections' => array_values(array_unique(array_merge(
                        $current['metadata']['folded_song_sections'] ?? [],
                        [$next['title'] ?? ServiceSectionType::SONG->label()]
                    ))),
                    'folded_song_duration_seconds' => (float) $next['duration'],
                ]);

                $folded[] = [
                    'church_service_item_id' => $current['church_service_item_id'],
                    'section_type' => ServiceSectionType::SERMON->value,
                    'section_order' => $current['section_order'],
                    'title' => $current['title'],
                    'start_time' => (float) $current['start_time'],
                    'end_time' => (float) $afterNext['end_time'],
                    'duration' => max(0.0, (float) $afterNext['end_time'] - (float) $current['start_time']),
                    'confidence' => max((float) $current['confidence'], (float) $afterNext['confidence']),
                    'status' => ServiceSectionStatus::IDENTIFIED->value,
                    'needs_manual_review' => false,
                    'source_segment_ids' => array_values(array_unique(array_merge(
                        $this->normaliseSourceSegmentIds($current['source_segment_ids']),
                        $this->normaliseSourceSegmentIds($next['source_segment_ids']),
                        $this->normaliseSourceSegmentIds($afterNext['source_segment_ids'])
                    ))),
                    'metadata' => $mergedMetadata,
                ];

                $index += 3;

                continue;
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
