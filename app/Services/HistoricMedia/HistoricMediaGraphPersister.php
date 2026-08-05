<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Data\HistoricProcessingResultImportPlan;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
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
        /**
         * The run must exist before any publication is written: sermons carry
         * `livestream_processing_id`, whose foreign key targets
         * `media_processing_logs.processing_id`. The run's own `sermon_id` back
         * reference can only be filled once the main publication exists, so it
         * is a second write rather than part of the insert.
         */
        $run = $this->createRun($graph);
        $publications = $this->createPublications($graph['publications'] ?? [], $processingId);
        $run->sermon_id = $publications['main']->id ?? null;
        $run->save();
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
     * The bundle's scripture_filters are deliberately not inserted. SermonObserver
     * owns that index and rebuilds it from `reference` on every save, so inserting
     * the bundle's rows either collides with the observer's on the unique key or is
     * silently replaced by them moments later. The contract classifies the field as
     * deterministically rebuilt for exactly this reason.
     *
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
                'filetype' => $publication['filetype'] ?? 'mp3',
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
                'preacher_source' => $publication['preacher_source'] ?? null,
                'preacher_confidence' => $publication['preacher_confidence'] ?? null,
                'needs_preacher_review' => $publication['needs_preacher_review'] ?? false,
                'source_type' => $publication['source_type'] ?? null,
                'video_quality_status' => $publication['video_quality_status'] ?? null,
                'video_quality_reason' => $publication['video_quality_reason'] ?? null,
                'video_visibility_override' => $publication['video_visibility_override'] ?? null,
                'video_quality_assessed_at' => $publication['video_quality_assessed_at'] ?? null,
                'thumbnail_generated_at' => $publication['thumbnail_generated_at'] ?? null,
                'thumbnail_metadata' => $publication['thumbnail_metadata'] ?? null,
                'preacher' => $preacher?->name,
                'preacher_id' => $preacher?->id,
                'livestream_processing_id' => $processingId,
            ]);
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
    private function createRun(array $graph): MediaProcessingLog
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
                'metadata' => $payload['metadata'] ?? null,
                'song_match_type' => $payload['song_match_type'],
                /**
                 * Sections are born unpublished. `service_sections_publication_media_check`
                 * refuses an approved or published row whose extraction media and
                 * timestamp are absent, and those only exist once the assets have been
                 * copied. finaliseSections() applies the bundle's real publication state
                 * afterwards.
                 */
                'publication_status' => ServiceSectionPublicationStatus::NotApplicable,
                'published_sermon_id' => null,
                'published_at' => null,
                'extracted_at' => null,
                'unpublished_expires_at' => null,
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
        $allocatedContent = [];

        foreach ($this->assets->expand($plan->assets) as $asset) {
            $role = $asset['role'];
            $extension = pathinfo($asset['path'], PATHINFO_EXTENSION) ?: 'bin';

            if (str_starts_with($role, 'publication:')) {
                $publicationRole = HistoricProcessingResultAssetRole::parsePublication($role);
                $publication = $publicationRole === null
                    ? null
                    : $this->publicationForRole($publicationRole['publication_key'], $publications);

                if (! $publication instanceof Sermon) {
                    throw new RuntimeException("Cannot allocate publication asset role {$role}.");
                }

                $variant = $publicationRole['type'] === 'field'
                    ? null
                    : HistoricProcessingResultAssetRole::thumbnailVariant($publicationRole['field']);
                $filename = match ($publicationRole['type']) {
                    'field' => $this->assetFilename($publicationRole['field'], $extension),
                    'thumbnail_metadata' => "thumbnail-{$variant}.{$extension}",
                    'thumbnail_candidate' => "thumbnail-candidate-{$publicationRole['candidate_index']}-{$variant}.{$extension}",
                };

                $destinations[$role] = "sermons/{$publication->id}/{$filename}";
            } elseif (str_starts_with($role, 'section:')) {
                $sectionRole = HistoricProcessingResultAssetRole::parseSection($role);
                $section = $sectionRole === null ? null : $sections[$sectionRole['section_key']] ?? null;

                if (! $section instanceof ServiceSection) {
                    throw new RuntimeException("Cannot allocate section asset role {$role}.");
                }

                $field = $sectionRole['field'] === 'extracted_audio_path' ? 'audio' : 'video';
                $destinations[$role] = "sermons/sections/{$section->id}/{$field}.{$extension}";
            } elseif (str_starts_with($role, 'song_video:')) {
                $songVideoRole = HistoricProcessingResultAssetRole::parseSongVideo($role);
                $section = $songVideoRole === null
                    ? null
                    : $sections[$songVideoRole['section_key']] ?? null;
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

            /**
             * Two roles may legitimately share a destination when they name the same
             * physical content. Two different content objects landing on one key would
             * silently overwrite each other, so refuse it where it happens rather than
             * letting the copy fail later as an unexplained manifest mismatch.
             */
            $destination = $destinations[$role];
            $existing = $allocatedContent[$destination] ?? null;

            if ($existing !== null && $existing !== $asset['sha256']) {
                throw new RuntimeException(
                    "Asset role {$role} allocates destination {$destination}, which is already allocated to different content."
                );
            }

            $allocatedContent[$destination] = $asset['sha256'];
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
        /**
         * Accumulate every remap in memory and write each record once. A sermon can
         * own a dozen thumbnail roles, and saving per role turned one publication
         * into a dozen round trips inside the import transaction.
         */
        $touchedPublications = [];
        $thumbnailMetadata = [];
        $touchedSections = [];

        foreach ($this->assets->expand($plan->assets) as $asset) {
            $role = $asset['role'];
            $path = $destinations[$role];

            if (str_starts_with($role, 'run_')) {
                $run->setAttribute(Str::after($role, 'run_'), $path);
            }

            $publicationRole = HistoricProcessingResultAssetRole::parsePublication($role);

            if ($publicationRole !== null) {
                $publicationKey = $publicationRole['publication_key'];
                $publication = $this->publicationForRole($publicationKey, $publications);

                if (! $publication instanceof Sermon) {
                    throw new RuntimeException("Cannot apply publication asset role {$role}.");
                }

                $touchedPublications[$publicationKey] = $publication;

                if ($publicationRole['type'] === 'field') {
                    $publication->setAttribute($publicationRole['field'], $path);
                } else {
                    $metadata = $thumbnailMetadata[$publicationKey]
                        ?? $publication->thumbnail_metadata?->toArray()
                        ?? [];

                    if ($publicationRole['type'] === 'thumbnail_metadata') {
                        $metadata[$publicationRole['field']] = $path;
                    } else {
                        $candidates = $metadata['thumbnail_candidates'] ?? null;

                        if (! is_array($candidates)) {
                            throw new RuntimeException("Cannot apply thumbnail candidate asset role {$role}.");
                        }

                        $candidates = array_values($candidates);

                        if (! isset($candidates[$publicationRole['candidate_index']])) {
                            throw new RuntimeException("Cannot apply thumbnail candidate asset role {$role}.");
                        }

                        $candidates[$publicationRole['candidate_index']][$publicationRole['field']] = $path;
                        $metadata['thumbnail_candidates'] = $candidates;
                    }

                    $thumbnailMetadata[$publicationKey] = $metadata;
                }

                continue;
            }

            $sectionRole = HistoricProcessingResultAssetRole::parseSection($role);

            if ($sectionRole !== null && isset($sections[$sectionRole['section_key']])) {
                $section = $sections[$sectionRole['section_key']];
                $section->setAttribute($sectionRole['field'], $path);
                $touchedSections[$sectionRole['section_key']] = $section;

                continue;
            }

            $songVideoRole = HistoricProcessingResultAssetRole::parseSongVideo($role);

            if ($songVideoRole !== null && isset($sections[$songVideoRole['section_key']])) {
                SongVideo::query()
                    ->where('service_section_id', $sections[$songVideoRole['section_key']]->id)
                    ->update(['video_file_path' => $path]);
            }
        }

        foreach ($touchedPublications as $publicationKey => $publication) {
            if (isset($thumbnailMetadata[$publicationKey])) {
                $publication->setAttribute('thumbnail_metadata', $thumbnailMetadata[$publicationKey]);
            }

            $publication->save();
        }

        $this->finaliseSections($plan, $sections, $publications, $touchedSections);
        $this->applyServiceArtifactPaths($run, $destinations);
        $run->save();
    }

    /**
     * Commit each section's extraction media before naming its publication state.
     * MySQL's `service_sections_publication_media_check` refuses an approved or
     * published row without extraction media and a timestamp, so collapsing these
     * two passes into one save reintroduces B2.
     *
     * @param  array<string, ServiceSection>  $sections
     * @param  array<string, Sermon>  $publications
     * @param  array<string, ServiceSection>  $touchedSections
     */
    private function finaliseSections(
        HistoricProcessingResultImportPlan $plan,
        array $sections,
        array $publications,
        array $touchedSections,
    ): void {
        $payloads = [];

        foreach ($plan->service['media_graph']['sections'] ?? [] as $payload) {
            if (is_array($payload) && is_string($payload['section_key'] ?? null)) {
                $payloads[$payload['section_key']] = $payload;
            }
        }

        /** @var array<string, array<string, mixed>> $finalisable */
        $finalisable = [];

        foreach (array_keys($sections) as $sectionKey) {
            $payload = $payloads[$sectionKey] ?? null;

            if (! is_array($payload)) {
                throw new RuntimeException("Cannot finalise unknown section {$sectionKey}.");
            }

            $finalisable[$sectionKey] = $payload;
        }

        foreach ($finalisable as $sectionKey => $payload) {
            $section = $sections[$sectionKey];
            $section->extracted_at = $payload['extracted_at'] ?? null;

            if (isset($touchedSections[$sectionKey]) || $section->extracted_at !== null) {
                $section->save();
            }
        }

        foreach ($finalisable as $sectionKey => $payload) {
            $section = $sections[$sectionKey];
            $this->assertPublicationStateIsSatisfiable($section, $payload, $sectionKey);

            $section->forceFill([
                'publication_status' => $payload['publication_status'],
                'published_sermon_id' => $publications[$sectionKey]->id ?? null,
                'published_at' => $payload['published_at'] ?? null,
                'unpublished_expires_at' => $payload['unpublished_expires_at'] ?? null,
            ])->save();
        }
    }

    /**
     * Restate `service_sections_publication_media_check` and
     * `service_sections_publication_link_check` in application terms before the
     * write. A bundle that promises a published section without its extraction
     * media is a bundle defect, and it should name the section rather than
     * surface as an anonymous MySQL constraint number.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertPublicationStateIsSatisfiable(
        ServiceSection $section,
        array $payload,
        string $sectionKey,
    ): void {
        $status = $payload['publication_status'];
        $status = $status instanceof ServiceSectionPublicationStatus
            ? $status
            : ServiceSectionPublicationStatus::from($status);

        if (! in_array($status, [
            ServiceSectionPublicationStatus::Approved,
            ServiceSectionPublicationStatus::Published,
        ], true)) {
            return;
        }

        $missing = [];

        if ($section->extracted_video_path === null) {
            $missing[] = 'extracted_video_path';
        }

        if ($section->extracted_at === null) {
            $missing[] = 'extracted_at';
        }

        if ($section->section_type !== ServiceSectionType::Song && $section->extracted_audio_path === null) {
            $missing[] = 'extracted_audio_path';
        }

        if ($status === ServiceSectionPublicationStatus::Published && ($payload['published_at'] ?? null) === null) {
            $missing[] = 'published_at';
        }

        if ($missing !== []) {
            throw new RuntimeException(
                "Section {$sectionKey} cannot become {$status->value} without ".implode(', ', $missing).'.'
            );
        }
    }

    /**
     * Rewrite recorded service-artifact paths to the destinations allocated for
     * their roles. A missing destination means the role this run derives and the
     * role the exporter emitted have diverged, which would otherwise leave a
     * staging path recorded against production media.
     *
     * @param  array<string, string>  $destinations
     */
    private function applyServiceArtifactPaths(MediaProcessingLog $run, array $destinations): void
    {
        $metadata = $run->processing_metadata?->toArray() ?? [];
        $serviceArtifacts = $metadata['service_artifacts'] ?? [];

        if (! is_array($serviceArtifacts)) {
            return;
        }

        foreach ($serviceArtifacts as $index => $artifact) {
            if (! is_array($artifact) || ! is_string($artifact['path'] ?? null)) {
                continue;
            }

            $role = HistoricProcessingResultAssetRole::serviceArtifact($artifact);

            if (! isset($destinations[$role])) {
                throw new RuntimeException("No production path was allocated for asset role {$role}.");
            }

            $serviceArtifacts[$index]['path'] = $destinations[$role];
        }

        $metadata['service_artifacts'] = $serviceArtifacts;
        $run->setAttribute('processing_metadata', $metadata);
    }

    /** @param array<string, Sermon> $publications */
    private function publicationForRole(string $publicationKey, array $publications): ?Sermon
    {
        $key = str_contains($publicationKey, ':main:') ? 'main' : Str::beforeLast($publicationKey, ':');

        return $publications[$key] ?? null;
    }

    private function assetFilename(string $field, string $extension): string
    {
        return match ($field) {
            'audio_file_path' => "audio.{$extension}",
            'video_file_path' => "video.{$extension}",
            'transcript_file_path' => "transcript.{$extension}",
            'thumbnail_file_path' => "thumbnail.{$extension}",
            default => throw new RuntimeException("Unknown publication asset field {$field}."),
        };
    }
}
