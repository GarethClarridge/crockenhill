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
use App\Models\Song;
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
            'sermon.scripturePassage',
            'serviceSections.publishedSermon.preacherProfile.aliases',
            'serviceSections.publishedSermon.scriptureFilters',
            'serviceSections.publishedSermon.scripturePassage',
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
                'metadata' => $this->portableMetadata($segment->metadata),
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

        $inventory['logical_hash'] = CanonicalJson::hash($this->portableHashProjection($inventory));

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
            'metadata' => $this->sectionMetadata($section, $serviceItems),
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
        $metadata = $log->processing_metadata?->toArray() ?? [];
        $scriptureOutcomes = data_get($metadata, 'historic_import.scripture_passage_outcomes', []);
        $scriptureOutcomes = is_array($scriptureOutcomes) ? $scriptureOutcomes : [];

        $primarySermon = $log->sermon;

        if ($primarySermon !== null) {
            $publications[] = $this->publication(
                "{$log->processing_id}:main:{$primarySermon->content_type->value}",
                null,
                $primarySermon,
                $scriptureOutcomes[$primarySermon->slug] ?? null,
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
                $scriptureOutcomes[$sermon->slug] ?? null,
            );
        }

        return $publications;
    }

    /** @return array<string, mixed> */
    private function publication(string $key, ?string $sectionKey, Sermon $sermon, mixed $scriptureOutcome): array
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
            'thumbnail_metadata' => $this->thumbnailMetadata($sermon->thumbnail_metadata?->toArray()),
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
            'scripture_passage' => $sermon->scripturePassage === null ? null : [
                'bible_id' => $sermon->scripturePassage->bible_id,
                'normalized_reference' => $sermon->scripturePassage->normalized_reference,
            ],
            'scripture_passage_outcome' => $sermon->scripturePassage === null
                ? $scriptureOutcome
                : ['status' => 'linked'],
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

    /**
     * @param  Collection<int|string, ChurchServiceItem>  $serviceItems
     * @return array<string, mixed>|null
     */
    private function sectionMetadata(ServiceSection $section, Collection $serviceItems): ?array
    {
        if ($section->getRawOriginal('metadata') === null) {
            return null;
        }

        $metadata = $section->metadata?->toArray();

        if ($metadata === null) {
            return null;
        }

        return $this->portableMetadata($metadata, $serviceItems);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>|null
     */
    private function thumbnailMetadata(?array $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        return $this->portableMetadata($metadata, allowAssetPaths: true);
    }

    /**
     * Metadata is part of the durable graph, but its JSON values can contain
     * local foreign keys from review-time data. Keep the known portable form
     * and fail closed for any other ID/path-shaped field.
     *
     * @param  Collection<int|string, ChurchServiceItem>|null  $serviceItems
     * @return array<string, mixed>|null
     */
    private function portableMetadata(
        mixed $metadata,
        ?Collection $serviceItems = null,
        bool $allowAssetPaths = false,
    ): ?array {
        if ($metadata === null) {
            return null;
        }

        if (! is_array($metadata)) {
            throw new RuntimeException('Historic durable metadata must be an object or null.');
        }

        return $this->portableMetadataValue($metadata, $serviceItems, $allowAssetPaths);
    }

    /**
     * @param  Collection<int|string, ChurchServiceItem>|null  $serviceItems
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function portableMetadataValue(
        array $metadata,
        ?Collection $serviceItems,
        bool $allowAssetPaths,
    ): array {
        $portable = [];

        foreach ($metadata as $key => $value) {
            if (str_ends_with($key, '_user_id')) {
                continue;
            }

            if ($key === 'preacher_id') {
                $preacherName = $metadata['preacher_name'] ?? null;

                if ($value !== null && (! is_numeric($value) || ! is_string($preacherName) || trim($preacherName) === '')) {
                    throw new RuntimeException('Historic durable metadata contains an unresolved preacher identity.');
                }

                if (is_string($preacherName) && trim($preacherName) !== '') {
                    $portable['preacher_slug'] = str($preacherName)->slug()->toString();
                }

                continue;
            }

            if ($key === 'song_id') {
                $songKey = $this->songCanonicalKey(is_numeric($value) ? (int) $value : null, $serviceItems);

                if ($value !== null && $songKey === null) {
                    throw new RuntimeException('Historic durable metadata contains an unresolved song identity.');
                }

                if ($songKey !== null) {
                    $portable['song_canonical_key'] = $songKey;
                }

                continue;
            }

            if ($key === 'base_church_service_item_id') {
                $itemId = is_numeric($value) ? (int) $value : null;
                $identity = $itemId === null
                    ? null
                    : $serviceItems?->get($itemId)?->canonical_identity;
                $identity ??= $itemId === null
                    ? null
                    : ChurchServiceItem::query()->whereKey($itemId)->value('canonical_identity');

                if ($value !== null && (! is_string($identity) || $identity === '')) {
                    throw new RuntimeException('Historic durable metadata contains an unresolved service item identity.');
                }

                if ($identity !== null) {
                    $portable['base_church_service_item_identity'] = $identity;
                }

                continue;
            }

            if (
                HistoricProcessingResultFieldClassifier::isPathKey($key)
                && (! $allowAssetPaths || ! in_array($key, [
                    'plain_thumbnail_path',
                    'card_thumbnail_path',
                    'overlay_thumbnail_path',
                    'plain_path',
                    'card_path',
                    'overlay_path',
                ], true))
            ) {
                throw new RuntimeException("Historic durable metadata contains an unclassified path field: {$key}.");
            }

            if (HistoricProcessingResultFieldClassifier::isIdentityKey($key) && ! $this->isKnownPortableIdentityKey($key, $allowAssetPaths)) {
                throw new RuntimeException("Historic durable metadata contains an unclassified identity field: {$key}.");
            }

            if (is_array($value)) {
                $portable[$key] = array_is_list($value)
                    ? array_map(
                        fn (mixed $item): mixed => is_array($item)
                            ? $this->portableMetadataValue($item, $serviceItems, $allowAssetPaths)
                            : $item,
                        $value,
                    )
                    : $this->portableMetadataValue($value, $serviceItems, $allowAssetPaths);

                continue;
            }

            $portable[$key] = $value;
        }

        return $portable;
    }

    /** @param Collection<int|string, ChurchServiceItem>|null $serviceItems */
    private function songCanonicalKey(?int $songId, ?Collection $serviceItems): ?string
    {
        if ($songId === null) {
            return null;
        }

        if ($serviceItems !== null) {
            foreach ($serviceItems as $item) {
                if ($item->song_id === $songId && is_string($item->song?->canonical_key)) {
                    return $item->song->canonical_key;
                }
            }
        }

        $canonicalKey = Song::query()->whereKey($songId)->value('canonical_key');

        return is_string($canonicalKey) ? $canonicalKey : null;
    }

    private function isKnownPortableIdentityKey(string $key, bool $allowAssetPaths): bool
    {
        return $allowAssetPaths && in_array($key, [
            'selected_thumbnail_candidate_id',
            'id',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $inventory
     * @return array<string, mixed>
     */
    private function portableHashProjection(array $inventory): array
    {
        $projection = $this->portableHashValue($inventory);
        unset($projection['logical_hash']);

        return $projection;
    }

    private function portableHashValue(mixed $value, string $path = ''): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $portable = [];

        foreach ($value as $nestedKey => $nestedValue) {
            if ($nestedKey === 'historic_promotion') {
                continue;
            }

            if (is_string($nestedKey) && HistoricProcessingResultFieldClassifier::isPathKey($nestedKey)) {
                $portable[$nestedKey] = null;

                continue;
            }

            if (
                is_string($nestedKey)
                && HistoricProcessingResultFieldClassifier::isIdentityKey($nestedKey)
                && $nestedKey !== 'processing_id'
                && ! (
                    $nestedKey === 'selected_thumbnail_candidate_id'
                    || ($nestedKey === 'id' && str_contains($path, 'thumbnail_candidates'))
                )
            ) {
                continue;
            }

            $nestedPath = $path === '' ? (string) $nestedKey : "{$path}.{$nestedKey}";
            $portable[$nestedKey] = $this->portableHashValue($nestedValue, $nestedPath);
        }

        return array_is_list($value) ? array_values($portable) : $portable;
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
