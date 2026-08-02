<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Enums\SermonSourceType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\SongVideo;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

class HistoricProcessingResultInventory
{
    public function __construct(
        private readonly HistoricProcessingMetadataSerializer $metadataSerializer,
        private readonly HistoricProcessingResultSectionKey $sectionKey,
    ) {}

    /**
     * @return array{
     *     processing_key: string,
     *     run: array<string, mixed>,
     *     steps: array<int, array<string, mixed>>,
     *     segments: array<int, array<string, mixed>>,
     *     sections: array<int, array<string, mixed>>,
     *     publications: array<int, array<string, mixed>>,
     *     song_videos: array<int, array<string, mixed>>,
     *     metadata: array<string, mixed>,
     *     logical_hash: string
     * }
     */
    public function build(MediaProcessingLog $processingLog): array
    {
        $processingLog->loadMissing([
            'processingSteps',
            'segments',
            'sermon.preacherProfile.aliases',
            'sermon.scriptureFilters',
            'serviceSections.publishedSermon.preacherProfile.aliases',
            'serviceSections.publishedSermon.scriptureFilters',
        ]);

        $segments = $processingLog->segments->sortBy('segment_index')->values();
        $segmentKeys = $segments->mapWithKeys(
            fn (LivestreamSegment $segment): array => [$segment->id => $this->segmentKey($processingLog, $segment)],
        );
        $sections = $processingLog->serviceSections->sortBy('section_order')->values();
        $serviceItemIds = $sections
            ->flatMap(function (ServiceSection $section): array {
                return array_values(array_filter([
                    $section->church_service_item_id,
                    $section->matched_item_id,
                    $section->expected_item_id,
                ], static fn (mixed $id): bool => is_int($id)));
            })
            ->unique()
            ->values()
            ->all();
        $serviceItems = $serviceItemIds === []
            ? new Collection
            : ChurchServiceItem::query()
                ->with('song')
                ->whereKey($serviceItemIds)
                ->get()
                ->keyBy('id');

        $inventory = [
            'processing_key' => $processingLog->processing_id,
            'run' => $this->run($processingLog),
            'steps' => $processingLog->processingSteps
                ->sortBy(fn ($step): string => "{$step->step}\0{$step->created_at->toISOString()}")
                ->map(fn ($step): array => [
                    'step' => $step->step,
                    'status' => $step->status->value,
                    'message' => $step->message,
                    'started_at' => $step->started_at?->toISOString(),
                    'completed_at' => $step->completed_at?->toISOString(),
                ])
                ->values()
                ->all(),
            'segments' => $segments->map(fn (LivestreamSegment $segment): array => [
                'segment_key' => $this->segmentKey($processingLog, $segment),
                'segment_index' => $segment->segment_index,
                'start_time' => $segment->start_time,
                'end_time' => $segment->end_time,
                'duration' => $segment->duration,
                'classification' => $segment->classification->value,
                'avg_rms' => $segment->avg_rms,
                'peak_rms' => $segment->peak_rms,
                'is_sermon_candidate' => $segment->is_sermon_candidate,
                'is_sermon_segment' => $segment->is_sermon_segment,
                'segment_order' => $segment->segment_order,
                'metadata' => $segment->metadata,
            ])->all(),
            'sections' => $sections->map(
                fn (ServiceSection $section): array => $this->section(
                    $processingLog,
                    $section,
                    $segmentKeys->all(),
                    $serviceItems,
                ),
            )->all(),
            'publications' => $this->publications($processingLog, $sections, $serviceItems),
            'song_videos' => $this->songVideos($processingLog, $sections, $serviceItems),
            'metadata' => $this->metadataSerializer->serialize($processingLog->processing_metadata?->toArray() ?? []),
        ];

        $inventory['logical_hash'] = CanonicalJson::hash($inventory);

        return $inventory;
    }

    /** @return array<string, mixed> */
    private function run(MediaProcessingLog $log): array
    {
        return [
            'processing_id' => $log->processing_id,
            'processing_type' => $log->processing_type->value,
            'status' => $log->status->value,
            'current_step' => $log->current_step,
            'error_message' => $log->error_message,
            'original_filename' => $log->original_filename,
            'file_hash' => $log->file_hash,
            'file_size' => $log->file_size,
            'duration' => $log->duration,
            'extracted_date' => $log->extracted_date?->toDateString(),
            'extracted_service' => $log->extracted_service?->value,
            'audio_file_path' => $log->audio_file_path,
            'video_file_path' => $log->video_file_path,
            'transcript_file_path' => $log->transcript_file_path,
            'rms_log_path' => $log->rms_log_path,
            'sermon_start_time' => $log->sermon_start_time,
            'sermon_end_time' => $log->sermon_end_time,
            'threshold_method' => $log->threshold_method,
            'adaptive_threshold' => $log->adaptive_threshold,
            'rms_stats' => $log->rms_stats,
            'started_at' => $log->started_at?->toISOString(),
            'completed_at' => $log->completed_at?->toISOString(),
            'is_degraded_completion' => (bool) $log->is_degraded_completion,
        ];
    }

    /**
     * @param  array<int, string>  $segmentKeys
     * @param  Collection<int|string, ChurchServiceItem>  $serviceItems
     * @return array<string, mixed>
     */
    private function section(
        MediaProcessingLog $log,
        ServiceSection $section,
        array $segmentKeys,
        Collection $serviceItems,
    ): array {
        $sourceSegmentKeys = [];

        foreach ($section->source_segment_ids as $segmentId) {
            if (! isset($segmentKeys[$segmentId])) {
                throw new RuntimeException("Section {$section->section_order} references a segment outside processing run {$log->processing_id}.");
            }

            $sourceSegmentKeys[] = $segmentKeys[$segmentId];
        }

        return [
            'section_key' => $this->sectionKey($log, $section, $serviceItems),
            'section_order' => $section->section_order,
            'section_type' => $section->section_type->value,
            'title' => $section->title,
            'summary' => $section->summary,
            'metadata' => $this->sectionMetadata($section),
            'start_time' => $section->start_time,
            'end_time' => $section->end_time,
            'duration' => $section->duration,
            'confidence' => $section->confidence,
            'status' => $section->status->value,
            'needs_manual_review' => $section->needs_manual_review,
            'source_segment_keys' => $sourceSegmentKeys,
            'service_item_identity' => $this->serviceItemIdentity($section->church_service_item_id, $serviceItems),
            'matched_item_identity' => $this->serviceItemIdentity($section->matched_item_id, $serviceItems),
            'expected_item_identity' => $this->serviceItemIdentity($section->expected_item_id, $serviceItems),
            'song_match_type' => $section->song_match_type?->value,
            'publication_status' => $section->publication_status->value,
            'extracted_video_path' => $section->extracted_video_path,
            'extracted_audio_path' => $section->extracted_audio_path,
            'extracted_at' => $section->extracted_at?->toISOString(),
            'published_at' => $section->published_at?->toISOString(),
            'unpublished_expires_at' => $section->unpublished_expires_at?->toISOString(),
            'published_publication_key' => $section->publishedSermon === null
                ? null
                : $this->sectionKey($log, $section, $serviceItems).':'.$section->publishedSermon->content_type->value,
        ];
    }

    /**
     * @param  Collection<int, ServiceSection>  $sections
     * @param  Collection<int|string, ChurchServiceItem>  $serviceItems
     * @return array<int, array<string, mixed>>
     */
    private function publications(MediaProcessingLog $log, Collection $sections, Collection $serviceItems): array
    {
        $publications = [];

        $primarySermon = $log->sermon;

        if ($primarySermon !== null) {
            $publications[] = $this->publication(
                "{$log->processing_id}:main:{$primarySermon->content_type->value}",
                null,
                $primarySermon,
            );
        }

        foreach ($sections as $section) {
            $sermon = $section->publishedSermon;

            if ($sermon === null) {
                continue;
            }

            $publications[] = $this->publication(
                $this->sectionKey($log, $section, $serviceItems).':'.$sermon->content_type->value,
                $this->sectionKey($log, $section, $serviceItems),
                $sermon,
            );
        }

        return $publications;
    }

    /** @return array<string, mixed> */
    private function publication(string $key, ?string $sectionKey, Sermon $sermon): array
    {
        $preacher = $sermon->preacherProfile;

        return [
            'publication_key' => $key,
            'section_key' => $sectionKey,
            'content_type' => $sermon->content_type->value,
            'date' => $sermon->date->toDateString(),
            'service' => $sermon->service?->value,
            'slug' => $sermon->slug,
            'filetype' => $sermon->filetype,
            'title' => $sermon->title,
            'reference' => $sermon->reference,
            'series' => $sermon->series,
            'summary' => $sermon->summary,
            'meta_description' => $sermon->getAttribute('meta_description'),
            'points' => $sermon->points,
            'show_summary' => $sermon->show_summary,
            'show_points' => $sermon->show_points,
            'duration' => $sermon->duration,
            'segment_start_time' => $sermon->segment_start_time,
            'segment_end_time' => $sermon->segment_end_time,
            'preacher_source' => $sermon->preacher_source?->value,
            'preacher_confidence' => $sermon->preacher_confidence,
            'needs_preacher_review' => $sermon->needs_preacher_review,
            'source_type' => $sermon->source_type instanceof SermonSourceType
                ? $sermon->source_type->value
                : SermonSourceType::Manual->value,
            'video_quality_status' => $sermon->videoQualityStatus()->value,
            'video_quality_reason' => $sermon->video_quality_reason,
            'video_visibility_override' => $sermon->videoVisibilityOverride()->value,
            'video_quality_assessed_at' => $sermon->video_quality_assessed_at?->toISOString(),
            'thumbnail_generated_at' => $sermon->thumbnail_generated_at?->toISOString(),
            'thumbnail_metadata' => $sermon->thumbnail_metadata?->toArray(),
            'preacher_display_name' => $sermon->preacher,
            'preacher' => $preacher === null ? null : [
                'name' => $preacher->name,
                'slug' => $preacher->slug,
                'aliases' => $preacher->aliases->pluck('alias')->sort()->values()->all(),
            ],
            'scripture_filters' => $sermon->scriptureFilters
                ->map(fn ($filter): array => [
                    'bible_book' => $filter->bible_book,
                    'bible_chapter' => $filter->bible_chapter,
                ])
                ->values()
                ->all(),
            'audio_file_path' => $sermon->audio_file_path,
            'video_file_path' => $sermon->video_file_path,
            'transcript_file_path' => $sermon->transcript_file_path,
            'thumbnail_file_path' => $sermon->thumbnail_file_path,
        ];
    }

    /**
     * @param  Collection<int, ServiceSection>  $sections
     * @param  Collection<int|string, ChurchServiceItem>  $serviceItems
     * @return array<int, array<string, mixed>>
     */
    private function songVideos(MediaProcessingLog $log, Collection $sections, Collection $serviceItems): array
    {
        $sectionKeys = $sections->mapWithKeys(
            fn (ServiceSection $section): array => [$section->id => $this->sectionKey($log, $section, $serviceItems)],
        );

        return array_values(SongVideo::query()
            ->with([
                'song:id,canonical_key',
                'churchService:id,date,service',
            ])
            ->whereIn('service_section_id', $sectionKeys->keys())
            ->orderBy('service_section_id')
            ->get()
            ->map(fn (SongVideo $video): array => [
                'section_key' => $sectionKeys->get($video->service_section_id),
                'song_canonical_key' => $video->song->canonical_key,
                'church_service_identity' => $this->churchServiceIdentity($video->churchService),
                'video_file_path' => $video->video_file_path,
                'duration' => $video->duration,
                'recorded_date' => $video->recorded_date?->toDateString(),
                'is_featured' => $video->is_featured,
            ])
            ->all());
    }

    /**
     * @param  Collection<int|string, ChurchServiceItem>  $serviceItems
     */
    private function serviceItemIdentity(?int $itemId, Collection $serviceItems): ?string
    {
        if ($itemId === null) {
            return null;
        }

        $identity = $serviceItems->get($itemId)?->canonical_identity;

        return is_string($identity) ? $identity : null;
    }

    private function churchServiceIdentity(?ChurchService $service): ?string
    {
        if ($service === null) {
            return null;
        }

        return $service->date->toDateString().'|'.$service->service->value;
    }

    /** @return array<string, mixed>|null */
    private function sectionMetadata(ServiceSection $section): ?array
    {
        if ($section->getRawOriginal('metadata') === null) {
            return null;
        }

        return $section->metadata?->toArray();
    }

    private function segmentKey(MediaProcessingLog $log, LivestreamSegment $segment): string
    {
        return "{$log->processing_id}:segment:{$segment->segment_index}";
    }

    /** @param Collection<int|string, ChurchServiceItem> $serviceItems */
    private function sectionKey(MediaProcessingLog $log, ServiceSection $section, Collection $serviceItems): string
    {
        return $this->sectionKey->for(
            $log->processing_id,
            $section,
            $this->serviceItemIdentity($section->church_service_item_id, $serviceItems),
        );
    }
}
