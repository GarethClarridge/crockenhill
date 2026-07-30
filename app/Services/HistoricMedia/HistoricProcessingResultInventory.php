<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\SongVideo;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

class HistoricProcessingResultInventory
{
    public function __construct(
        private readonly HistoricProcessingMetadataSerializer $metadataSerializer,
    ) {}

    /**
     * @return array{
     *     processing_key: string,
     *     run: array<string, mixed>,
     *     steps: list<array<string, mixed>>,
     *     segments: list<array<string, mixed>>,
     *     sections: list<array<string, mixed>>,
     *     publications: list<array<string, mixed>>,
     *     song_videos: list<array<string, mixed>>,
     *     metadata: array<string, mixed>,
     *     logical_hash: string
     * }
     */
    public function build(MediaProcessingLog $processingLog): array
    {
        $processingLog->loadMissing([
            'processingSteps',
            'segments',
            'serviceSections.publishedSermon.preacherProfile',
        ]);

        $segments = $processingLog->segments->sortBy('segment_index')->values();
        $segmentKeys = $segments->mapWithKeys(
            fn (LivestreamSegment $segment): array => [$segment->id => $this->segmentKey($processingLog, $segment)],
        );
        $sections = $processingLog->serviceSections->sortBy('section_order')->values();

        $inventory = [
            'processing_key' => $processingLog->processing_id,
            'run' => $this->run($processingLog),
            'steps' => $processingLog->processingSteps
                ->sortBy(fn ($step): string => "{$step->step}\0{$step->created_at?->toISOString()}")
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
                fn (ServiceSection $section): array => $this->section($processingLog, $section, $segmentKeys->all()),
            )->all(),
            'publications' => $this->publications($processingLog, $sections),
            'song_videos' => $this->songVideos($processingLog, $sections),
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
     * @return array<string, mixed>
     */
    private function section(MediaProcessingLog $log, ServiceSection $section, array $segmentKeys): array
    {
        $sourceSegmentKeys = [];

        foreach ($section->source_segment_ids as $segmentId) {
            if (! isset($segmentKeys[$segmentId])) {
                throw new RuntimeException("Section {$section->section_order} references a segment outside processing run {$log->processing_id}.");
            }

            $sourceSegmentKeys[] = $segmentKeys[$segmentId];
        }

        return [
            'section_key' => $this->sectionKey($log, $section),
            'section_order' => $section->section_order,
            'section_type' => $section->section_type->value,
            'title' => $section->title,
            'summary' => $section->summary,
            'start_time' => $section->start_time,
            'end_time' => $section->end_time,
            'duration' => $section->duration,
            'confidence' => $section->confidence,
            'status' => $section->status->value,
            'needs_manual_review' => $section->needs_manual_review,
            'source_segment_keys' => $sourceSegmentKeys,
            'song_match_type' => $section->song_match_type?->value,
            'publication_status' => $section->publication_status->value,
            'extracted_video_path' => $section->extracted_video_path,
            'extracted_audio_path' => $section->extracted_audio_path,
            'published_at' => $section->published_at?->toISOString(),
        ];
    }

    /**
     * @param  Collection<int, ServiceSection>  $sections
     * @return list<array<string, mixed>>
     */
    private function publications(MediaProcessingLog $log, Collection $sections): array
    {
        return $sections
            ->filter(fn (ServiceSection $section): bool => $section->publishedSermon !== null)
            ->map(fn (ServiceSection $section): array => [
                'publication_key' => $this->sectionKey($log, $section).':'.$section->publishedSermon->content_type->value,
                'section_key' => $this->sectionKey($log, $section),
                'content_type' => $section->publishedSermon->content_type->value,
                'date' => $section->publishedSermon->date->toDateString(),
                'service' => $section->publishedSermon->service?->value,
                'slug' => $section->publishedSermon->slug,
                'title' => $section->publishedSermon->title,
                'reference' => $section->publishedSermon->reference,
                'preacher_slug' => $section->publishedSermon->preacherProfile?->slug,
                'audio_file_path' => $section->publishedSermon->audio_file_path,
                'video_file_path' => $section->publishedSermon->video_file_path,
                'transcript_file_path' => $section->publishedSermon->transcript_file_path,
                'thumbnail_file_path' => $section->publishedSermon->thumbnail_file_path,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, ServiceSection>  $sections
     * @return list<array<string, mixed>>
     */
    private function songVideos(MediaProcessingLog $log, Collection $sections): array
    {
        $sectionKeys = $sections->mapWithKeys(
            fn (ServiceSection $section): array => [$section->id => $this->sectionKey($log, $section)],
        );

        return SongVideo::query()
            ->with('song:id,canonical_key')
            ->whereIn('service_section_id', $sectionKeys->keys())
            ->orderBy('service_section_id')
            ->get()
            ->map(fn (SongVideo $video): array => [
                'section_key' => $sectionKeys->get($video->service_section_id),
                'song_canonical_key' => $video->song->canonical_key,
                'video_file_path' => $video->video_file_path,
                'duration' => $video->duration,
                'recorded_date' => $video->recorded_date?->toDateString(),
                'is_featured' => $video->is_featured,
            ])
            ->all();
    }

    private function segmentKey(MediaProcessingLog $log, LivestreamSegment $segment): string
    {
        return "{$log->processing_id}:segment:{$segment->segment_index}";
    }

    private function sectionKey(MediaProcessingLog $log, ServiceSection $section): string
    {
        return "{$log->processing_id}:section:{$section->section_order}:{$section->classificationSignature()}";
    }
}
