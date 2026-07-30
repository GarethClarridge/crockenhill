<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Data\HistoricProcessingResultImportPlan;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\SermonProcessingStep;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use Illuminate\Support\Str;
use RuntimeException;

class HistoricMediaGraphPersister
{
    public function __construct(
        private readonly HistoricProcessingResultAssetTransfer $assets,
    ) {}

    /**
     * @return array{processing_log: MediaProcessingLog, created_assets: list<string>}
     */
    public function persist(HistoricProcessingResultImportPlan $plan): array
    {
        $graph = $plan->service['media_graph'];
        $processingId = $graph['processing_key'];
        $publications = $this->createPublications($graph['publications'] ?? [], $processingId);
        $run = $this->createRun($graph, $publications['main'] ?? null);
        $segments = $this->createSegments($run, $graph['segments'] ?? []);
        $sections = $this->createSections($run, $graph['sections'] ?? [], $segments, $publications);
        $this->createSteps($processingId, $graph['steps'] ?? []);
        $this->createSongVideos($plan, $sections);
        $destinations = $this->assetDestinations($plan, $run, $sections, $publications);
        $createdAssets = $this->assets->copyToDestinations($plan->assets, $destinations);

        try {
            $this->applyAllocatedPaths($plan, $run, $sections, $publications, $destinations);
        } catch (\Throwable $exception) {
            $this->assets->cleanup($createdAssets);

            throw $exception;
        }

        return ['processing_log' => $run->fresh() ?? $run, 'created_assets' => $createdAssets];
    }

    /**
     * @param  array<int, array<string, mixed>>  $publications
     * @return array<string, Sermon>
     */
    private function createPublications(array $publications, string $processingId): array
    {
        $created = [];

        foreach ($publications as $publication) {
            $preacher = $this->resolvePreacher($publication['preacher'] ?? null);

            $sermon = Sermon::query()->create([
                'title' => $publication['title'],
                'date' => $publication['date'],
                'service' => $publication['service'],
                'content_type' => $publication['content_type'],
                'slug' => $publication['slug'],
                'reference' => $publication['reference'],
                'series' => $publication['series'] ?? null,
                'summary' => $publication['summary'] ?? null,
                'meta_description' => $publication['meta_description'] ?? null,
                'points' => $publication['points'] ?? null,
                'show_summary' => $publication['show_summary'] ?? false,
                'show_points' => $publication['show_points'] ?? false,
                'duration' => $publication['duration'] ?? null,
                'segment_start_time' => $publication['segment_start_time'] ?? null,
                'segment_end_time' => $publication['segment_end_time'] ?? null,
                'preacher' => $preacher?->name,
                'preacher_id' => $preacher?->id,
                'livestream_processing_id' => $processingId,
            ]);
            $sermon->scriptureFilters()->createMany($publication['scripture_filters'] ?? []);
            $key = $publication['section_key'] ?? 'main';
            $created[$key] = $sermon;
        }

        return $created;
    }

    private function resolvePreacher(mixed $payload): ?Preacher
    {
        if (! is_array($payload)) {
            return null;
        }

        $slug = $payload['slug'] ?? null;
        $name = $payload['name'] ?? null;

        if (! is_string($slug) || ! is_string($name)) {
            throw new RuntimeException('Bundle preacher natural identity is incomplete.');
        }

        $preacher = Preacher::query()->where('slug', $slug)->first();

        if ($preacher instanceof Preacher && $preacher->name !== $name) {
            throw new RuntimeException("Bundle preacher {$slug} conflicts with production.");
        }

        $preacher ??= Preacher::query()->create([
            'slug' => $slug,
            'name' => $name,
            'is_active' => true,
        ]);

        foreach ($payload['aliases'] ?? [] as $alias) {
            if (is_string($alias)) {
                $preacher->aliases()->firstOrCreate(['alias' => $alias]);
            }
        }

        return $preacher;
    }

    /** @param array<string, mixed> $graph */
    private function createRun(array $graph, ?Sermon $main): MediaProcessingLog
    {
        $run = $graph['run'];

        return MediaProcessingLog::query()->create([
            'processing_id' => $graph['processing_key'],
            'processing_type' => $run['processing_type'],
            'status' => $run['status'],
            'current_step' => $run['current_step'],
            'original_filename' => $run['original_filename'],
            'file_hash' => $run['file_hash'],
            'file_size' => $run['file_size'],
            'duration' => $run['duration'],
            'extracted_date' => $run['extracted_date'],
            'extracted_service' => $run['extracted_service'],
            'sermon_start_time' => $run['sermon_start_time'],
            'sermon_end_time' => $run['sermon_end_time'],
            'threshold_method' => $run['threshold_method'],
            'adaptive_threshold' => $run['adaptive_threshold'],
            'rms_stats' => $run['rms_stats'],
            'processing_metadata' => [
                ...$graph['metadata'],
                'historic_promotion' => [
                    'logical_hash' => $graph['logical_hash'],
                ],
            ],
            'sermon_id' => $main?->id,
            'started_at' => $run['started_at'],
            'completed_at' => $run['completed_at'],
            'is_degraded_completion' => $run['is_degraded_completion'],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $segmentPayloads
     * @return array<string, LivestreamSegment>
     */
    private function createSegments(MediaProcessingLog $run, array $segmentPayloads): array
    {
        $segments = [];

        foreach ($segmentPayloads as $payload) {
            $segment = $run->segments()->create(collect($payload)->except('segment_key')->all());
            $segments[$payload['segment_key']] = $segment;
        }

        return $segments;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sectionPayloads
     * @param  array<string, LivestreamSegment>  $segments
     * @param  array<string, Sermon>  $publications
     * @return array<string, ServiceSection>
     */
    private function createSections(
        MediaProcessingLog $run,
        array $sectionPayloads,
        array $segments,
        array $publications,
    ): array {
        $sections = [];

        foreach ($sectionPayloads as $payload) {
            $segmentIds = [];

            foreach ($payload['source_segment_keys'] as $key) {
                if (! is_string($key) || ! isset($segments[$key])) {
                    throw new RuntimeException('Bundle section references an unknown segment key.');
                }

                $segmentIds[] = $segments[$key]->id;
            }
            $section = $run->serviceSections()->create([
                'section_type' => $payload['section_type'],
                'section_order' => $payload['section_order'],
                'title' => $payload['title'],
                'summary' => $payload['summary'],
                'start_time' => $payload['start_time'],
                'end_time' => $payload['end_time'],
                'duration' => $payload['duration'],
                'confidence' => $payload['confidence'],
                'status' => $payload['status'],
                'needs_manual_review' => $payload['needs_manual_review'],
                'source_segment_ids' => $segmentIds,
                'song_match_type' => $payload['song_match_type'],
                'publication_status' => $payload['publication_status'],
                'published_sermon_id' => $publications[$payload['section_key']]->id ?? null,
                'published_at' => $payload['published_at'],
            ]);
            $sections[$payload['section_key']] = $section;
        }

        return $sections;
    }

    /** @param array<int, array<string, mixed>> $steps */
    private function createSteps(string $processingId, array $steps): void
    {
        foreach ($steps as $step) {
            SermonProcessingStep::query()->create(['processing_id' => $processingId, ...$step]);
        }
    }

    /** @param array<string, ServiceSection> $sections */
    private function createSongVideos(HistoricProcessingResultImportPlan $plan, array $sections): void
    {
        foreach ($plan->service['media_graph']['song_videos'] ?? [] as $payload) {
            $section = $sections[$payload['section_key']] ?? null;
            $song = Song::query()->where('canonical_key', $payload['song_canonical_key'])->first();

            if (! $section instanceof ServiceSection || ! $song instanceof Song) {
                throw new RuntimeException('Bundle song video cannot resolve its section and canonical song.');
            }

            SongVideo::query()->create([
                'song_id' => $song->id,
                'service_section_id' => $section->id,
                'video_file_path' => $payload['video_file_path'],
                'duration' => $payload['duration'],
                'recorded_date' => $payload['recorded_date'],
                'is_featured' => $payload['is_featured'],
            ]);
        }
    }

    /**
     * @param  array<string, ServiceSection>  $sections
     * @param  array<string, Sermon>  $publications
     * @return array<string, string>
     */
    private function assetDestinations(
        HistoricProcessingResultImportPlan $plan,
        MediaProcessingLog $run,
        array $sections,
        array $publications,
    ): array {
        $destinations = [];

        foreach ($plan->assets as $asset) {
            $role = $asset['role'];
            $extension = pathinfo($asset['path'], PATHINFO_EXTENSION) ?: 'bin';

            if (str_starts_with($role, 'publication:')) {
                $field = Str::afterLast($role, ':');
                $publicationKey = Str::beforeLast(Str::after($role, 'publication:'), ':');
                $key = str_contains($publicationKey, ':main:') ? 'main' : Str::beforeLast($publicationKey, ':');
                $publication = $publications[$key] ?? null;

                if (! $publication instanceof Sermon) {
                    throw new RuntimeException("Cannot allocate publication asset role {$role}.");
                }

                $destinations[$role] = "sermons/{$publication->id}/".$this->assetFilename($field, $extension);
            } elseif (str_starts_with($role, 'section:')) {
                $field = str_ends_with($role, ':extracted_audio_path') ? 'audio' : 'video';
                $sectionKey = Str::beforeLast(Str::after($role, 'section:'), ':extracted_');
                $section = $sections[$sectionKey] ?? null;

                if (! $section instanceof ServiceSection) {
                    throw new RuntimeException("Cannot allocate section asset role {$role}.");
                }

                $destinations[$role] = "sermons/sections/{$section->id}/{$field}.{$extension}";
            } elseif (str_starts_with($role, 'song_video:')) {
                $section = $sections[Str::after($role, 'song_video:')] ?? null;
                $video = $section?->id !== null
                    ? SongVideo::query()->where('service_section_id', $section->id)->first()
                    : null;

                if (! $video instanceof SongVideo) {
                    throw new RuntimeException("Cannot allocate song video asset role {$role}.");
                }

                $destinations[$role] = "sermons/songs/{$video->song_id}/{$section->id}.{$extension}";
            } else {
                $destinations[$role] = "service-transcripts/{$run->processing_id}/".basename($asset['path']);
            }
        }

        return $destinations;
    }

    /**
     * @param  array<string, ServiceSection>  $sections
     * @param  array<string, Sermon>  $publications
     * @param  array<string, string>  $destinations
     */
    private function applyAllocatedPaths(
        HistoricProcessingResultImportPlan $plan,
        MediaProcessingLog $run,
        array $sections,
        array $publications,
        array $destinations,
    ): void {
        foreach ($plan->assets as $asset) {
            $role = $asset['role'];
            $path = $destinations[$role];

            if (str_starts_with($role, 'run_')) {
                $run->setAttribute(Str::after($role, 'run_'), $path);
            }

            foreach ($publications as $sermon) {
                $publicationRole = $sermon === ($publications['main'] ?? null)
                    ? 'publication:'.$plan->service['media_graph']['processing_key'].":main:{$sermon->content_type->value}:"
                    : null;
                $matchesPublication = $publicationRole !== null
                    ? str_starts_with($role, $publicationRole)
                    : collect($publications)
                        ->filter(fn (Sermon $candidate): bool => $candidate->is($sermon))
                        ->keys()
                        ->contains(fn (string $key): bool => str_starts_with($role, "publication:{$key}:"));

                if ($matchesPublication) {
                    $field = Str::afterLast($role, ':');
                    $sermon->setAttribute($field, $path)->save();
                }
            }

            foreach ($sections as $sectionKey => $section) {
                if (str_starts_with($role, "section:{$sectionKey}:")) {
                    $section->setAttribute(Str::afterLast($role, ':'), $path)->save();
                }

                if ($role === "song_video:{$sectionKey}") {
                    SongVideo::query()->where('service_section_id', $section->id)->update(['video_file_path' => $path]);
                }
            }
        }

        $metadata = $run->processing_metadata?->toArray() ?? [];
        $serviceArtifacts = $metadata['service_artifacts'] ?? [];

        if (is_array($serviceArtifacts)) {
            foreach ($serviceArtifacts as $index => $artifact) {
                if (! is_array($artifact)) {
                    continue;
                }

                $role = 'service_artifact:'.($artifact['kind'] ?? 'unknown').':'.($artifact['sha256'] ?? hash('sha256', (string) ($artifact['path'] ?? '')));

                if (isset($destinations[$role])) {
                    $serviceArtifacts[$index]['path'] = $destinations[$role];
                }
            }

            $metadata['service_artifacts'] = $serviceArtifacts;
            $run->setAttribute('processing_metadata', $metadata);
        }

        $run->save();
    }

    private function assetFilename(string $field, string $extension): string
    {
        return match ($field) {
            'audio_file_path' => "audio.{$extension}",
            'video_file_path' => "video.{$extension}",
            'transcript_file_path' => "transcript.{$extension}",
            'thumbnail_file_path' => "thumbnail.{$extension}",
            default => "asset.{$extension}",
        };
    }
}
