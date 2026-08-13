<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Data\HistoricProcessingResultImportPlan;
use App\Enums\ChurchServiceItemSource;
use App\Enums\ChurchServiceOccurrenceState;
use App\Enums\MediaType;
use App\Enums\PreacherSource;
use App\Enums\ProcessingStatus;
use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Enums\SermonSourceType;
use App\Enums\SermonVideoQualityStatus;
use App\Enums\SermonVideoVisibilityOverride;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Models\SermonProcessingStep;
use App\Models\SermonScriptureFilter;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use App\Services\HistoricMedia\HistoricMediaGraphPersister;
use App\Services\HistoricMedia\HistoricNormalOutputServiceManifest;
use App\Services\HistoricMedia\HistoricProcessingResultAssetRole;
use App\Services\HistoricMedia\HistoricProcessingResultInventory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The WP0 normal-output canary: a complete, deterministic full-service graph
 * built through the real persistence path.
 *
 * It lives outside any one test class because more than one gate is defined
 * against it — the WP0 contract itself, and WP4's Bundle A round trip, which
 * must export *this* graph rather than a second hand-authored approximation of
 * it. Two fixtures would let the two gates drift apart silently.
 */
class HistoricNormalOutputCanary
{
    /** The run the canary fixture is built as, before it is persisted for real. */
    public const string SOURCE_PROCESSING_ID = '00000000-0000-0000-0000-000000000001';

    /** The run the real persistence path creates from it. */
    public const string TARGET_PROCESSING_ID = '00000000-0000-0000-0000-000000000002';

    /**
     * @return array{run: MediaProcessingLog, service: ChurchService, manifest: array<string, mixed>}
     */
    public function createCanary(): array
    {
        Storage::fake('local');

        $date = Carbon::create(2026, 8, 2, 10, 0, 0, 'UTC');
        $service = ChurchService::factory()->create([
            'date' => $date->toDateString(),
            'service' => SermonService::Morning,
            'source' => 'livestream',
            'summary' => 'A deterministic normal-output canary.',
            'notices' => [['title' => 'Canary notice', 'details' => null]],
            'chapter_markers' => [['title' => 'Song', 'start_time' => 0.0, 'end_time' => 120.0]],
        ]);
        $song = Song::factory()->create([
            'canonical_key' => 'repeated-song',
            'title' => 'Repeated Song',
        ]);
        $items = [
            ChurchServiceItem::factory()->create([
                'church_service_id' => $service->id,
                'position' => 1,
                'type' => 'songs',
                'source' => ChurchServiceItemSource::Livestream,
                'title' => 'Repeated Song',
                'song_id' => $song->id,
                'canonical_identity' => 'observed-anchor',
                'occurrence_state' => ChurchServiceOccurrenceState::PlannedAndObserved,
                'metadata' => [
                    'source_assertion_hashes' => ['a'.str_repeat('1', 63)],
                    'canary_detail' => 'preserve the complete durable item metadata',
                ],
            ]),
            ChurchServiceItem::factory()->create([
                'church_service_id' => $service->id,
                'position' => 2,
                'type' => 'custom',
                'source' => ChurchServiceItemSource::OpenLp,
                'title' => 'Planned-only item',
                'canonical_identity' => 'planned-only',
                'occurrence_state' => ChurchServiceOccurrenceState::PlannedOnly,
                'metadata' => ['source_assertion_hashes' => []],
            ]),
            ChurchServiceItem::factory()->create([
                'church_service_id' => $service->id,
                'position' => 3,
                'type' => 'songs',
                'source' => ChurchServiceItemSource::Livestream,
                'title' => 'Repeated Song',
                'song_id' => $song->id,
                'canonical_identity' => 'observed-anchor-2',
                'occurrence_state' => ChurchServiceOccurrenceState::PlannedAndObserved,
                'metadata' => ['source_assertion_hashes' => ['b'.str_repeat('2', 63)]],
            ]),
        ];
        $preacher = Preacher::factory()->create([
            'name' => 'Canary Preacher',
            'slug' => 'canary-preacher',
        ]);
        $preacher->aliases()->create(['alias' => 'canary speaker']);
        $childrensTalkSpeaker = Preacher::factory()->create([
            'name' => 'Canary Children Speaker',
            'slug' => 'canary-children-speaker',
        ]);
        $processingId = self::SOURCE_PROCESSING_ID;
        $scripturePassage = ScripturePassage::factory()->create([
            'bible_id' => 'eng-kjv',
            'normalized_reference' => 'John 3; Romans 8',
        ]);
        $mainSermon = Sermon::factory()->create([
            'date' => $date->toDateString(),
            'service' => SermonService::Morning,
            'content_type' => SermonContentType::Sermon,
            'title' => 'Canary sermon',
            'slug' => 'canary-sermon',
            'reference' => 'John 3; Romans 8',
            'scripture_passage_id' => $scripturePassage->id,
            'preacher' => $preacher->name,
            'preacher_id' => $preacher->id,
            'preacher_source' => PreacherSource::Manual,
            'preacher_confidence' => 1.0,
            'video_quality_status' => SermonVideoQualityStatus::Approved,
            'video_quality_reason' => 'Canary quality pass',
            'video_visibility_override' => SermonVideoVisibilityOverride::ForceShow,
            'video_quality_assessed_at' => $date,
            'source_type' => SermonSourceType::Livestream,
            'summary' => 'Canary summary',
            'points' => ['First point', 'Second point'],
            'audio_file_path' => 'shared/full-service.mp3',
            'video_file_path' => 'shared/main-video.mp4',
            'transcript_file_path' => 'shared/main-transcript.txt',
            'thumbnail_file_path' => 'shared/main-thumbnail.webp',
            'thumbnail_generated_at' => $date,
            'thumbnail_metadata' => [
                'timestamp' => 120.0,
                'video_duration' => 1800.0,
                'plain_thumbnail_path' => 'shared/main-thumbnail.webp',
            ],
            'duration' => 1800.0,
            'segment_start_time' => 900.0,
            'segment_end_time' => 2700.0,
        ]);
        $childrensTalk = Sermon::factory()->create([
            'date' => $date->toDateString(),
            'service' => SermonService::Morning,
            'content_type' => SermonContentType::ChildrensTalk,
            'title' => "Canary children's talk",
            'slug' => 'canary-childrens-talk',
            'preacher' => $childrensTalkSpeaker->name,
            'preacher_id' => $childrensTalkSpeaker->id,
            'preacher_source' => PreacherSource::Manual,
            'needs_preacher_review' => false,
            'source_type' => SermonSourceType::Livestream,
            'show_summary' => true,
            'show_points' => true,
            'video_quality_status' => SermonVideoQualityStatus::Unassessed,
            'video_visibility_override' => SermonVideoVisibilityOverride::Default,
            'audio_file_path' => 'shared/childrens-talk.mp3',
            'video_file_path' => 'shared/childrens-talk.mp4',
            'transcript_file_path' => 'shared/childrens-talk.txt',
            'thumbnail_file_path' => 'shared/childrens-talk.webp',
        ]);
        $run = MediaProcessingLog::factory()->create([
            'processing_id' => $processingId,
            'processing_type' => MediaType::Livestream,
            'status' => ProcessingStatus::Completed,
            'current_step' => 'completed',
            'original_filename' => 'canary-live-stream.mp4',
            'file_hash' => str_repeat('c', 64),
            'file_size' => 123456,
            'duration' => 3600.0,
            'extracted_date' => $date->toDateString(),
            'extracted_service' => SermonService::Morning,
            'source_file_path' => 'ephemeral/source.mp4',
            'audio_file_path' => 'shared/full-service.mp3',
            'video_file_path' => 'shared/full-service.mp4',
            'transcript_file_path' => 'shared/full-service.txt',
            'rms_log_path' => 'shared/full-service-rms.json',
            'sermon_start_time' => 900.0,
            'sermon_end_time' => 2700.0,
            'threshold_method' => 'adaptive_rms',
            'adaptive_threshold' => 0.35,
            'rms_stats' => ['mean' => 0.25, 'peak' => 0.9],
            'processing_metadata' => [
                'service_artifacts' => [
                    ['kind' => 'rms', 'path' => 'shared/full-service-rms.json', 'sha256' => str_repeat('d', 64)],
                    ['kind' => 'transcript', 'path' => 'shared/full-service.txt', 'sha256' => str_repeat('e', 64)],
                ],
                'historic_import' => [
                    'tag' => 'canary',
                    'sources' => [['size' => 123456, 'sha256' => str_repeat('c', 64)]],
                    /**
                     * HIR3: `scripture_passage_outcome` is a required field, so
                     * the canary carries both settled shapes — the main sermon
                     * links a passage, and the children's talk, which carries a
                     * reference nothing resolved, travels with the approved
                     * terminal absence the curator settled it on.
                     */
                    'scripture_passage_outcomes' => [
                        'canary-childrens-talk' => ['status' => 'approved_absent', 'reason' => 'not_found'],
                    ],
                ],
            ],
            'sermon_id' => $mainSermon->id,
            'church_service_id' => $service->id,
            'started_at' => $date->copy()->subHour(),
            'completed_at' => $date,
            'is_degraded_completion' => false,
        ]);
        $mainSermon->update(['livestream_processing_id' => $processingId]);
        $segments = collect([
            [0, 0.0, 120.0, 'song'],
            [1, 120.0, 300.0, 'speech'],
            [2, 300.0, 2100.0, 'speech'],
        ])->map(fn (array $segment): LivestreamSegment => LivestreamSegment::factory()->create([
            'media_processing_log_id' => $run->id,
            'segment_index' => $segment[0],
            'start_time' => $segment[1],
            'end_time' => $segment[2],
            'duration' => $segment[2] - $segment[1],
            'classification' => $segment[3],
            'is_sermon_candidate' => $segment[3] === 'speech',
            'is_sermon_segment' => $segment[3] === 'speech',
            'segment_order' => $segment[0],
            'metadata' => ['fixture' => true],
        ]));
        $segmentsByIndex = $segments->keyBy('segment_index');
        SermonProcessingStep::factory()->createMany([
            ['processing_id' => $processingId, 'step' => 'segmentation', 'status' => ProcessingStatus::Completed, 'started_at' => $date->copy()->subMinutes(50), 'completed_at' => $date->copy()->subMinutes(45)],
            ['processing_id' => $processingId, 'step' => 'transcription', 'status' => ProcessingStatus::Completed, 'started_at' => $date->copy()->subMinutes(40), 'completed_at' => $date->copy()->subMinutes(20)],
            ['processing_id' => $processingId, 'step' => 'publication', 'status' => ProcessingStatus::Completed, 'started_at' => $date->copy()->subMinutes(15), 'completed_at' => $date->copy()->subMinutes(5)],
        ]);
        $sectionOne = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $items[0]->id,
            'section_type' => ServiceSectionType::Song,
            'section_order' => 1,
            'title' => 'Repeated Song',
            'start_time' => 0.0,
            'end_time' => 120.0,
            'duration' => 120.0,
            'status' => ServiceSectionStatus::Identified,
            'source_segment_ids' => [$segmentsByIndex[0]->id],
            'song_match_type' => ServiceSectionSongMatchType::Confirmed,
            'publication_status' => ServiceSectionPublicationStatus::Published,
            'extracted_video_path' => 'shared/song.mp4',
            'extracted_at' => $date,
            'published_at' => $date,
        ]);
        $sectionOne->update(['metadata' => null]);
        $sectionTwo = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::ChildrensTalk,
            'section_order' => 2,
            'title' => "Canary children's talk",
            'start_time' => 120.0,
            'end_time' => 300.0,
            'duration' => 180.0,
            'status' => ServiceSectionStatus::Identified,
            'source_segment_ids' => [$segmentsByIndex[1]->id],
            'metadata' => [
                'childrens_talk_speaker' => [
                    'reviewed' => [
                        'preacher_id' => $childrensTalkSpeaker->id,
                        'preacher_name' => $childrensTalkSpeaker->name,
                        'source' => 'manual',
                        'confidence' => 1.0,
                    ],
                ],
            ],
            'publication_status' => ServiceSectionPublicationStatus::Published,
            'published_sermon_id' => $childrensTalk->id,
            'extracted_video_path' => 'shared/childrens-talk.mp4',
            'extracted_audio_path' => 'shared/childrens-talk.mp3',
            'extracted_at' => $date,
            'published_at' => $date,
        ]);
        $sectionThree = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $items[2]->id,
            'section_type' => ServiceSectionType::Song,
            'section_order' => 3,
            'title' => 'Repeated Song',
            'start_time' => 300.0,
            'end_time' => 420.0,
            'duration' => 120.0,
            'status' => ServiceSectionStatus::Identified,
            'source_segment_ids' => [$segmentsByIndex[2]->id],
            'song_match_type' => ServiceSectionSongMatchType::Confirmed,
            'publication_status' => ServiceSectionPublicationStatus::Published,
            'extracted_video_path' => 'shared/song.mp4',
            'extracted_at' => $date,
            'published_at' => $date,
        ]);
        $sectionOne->update(['published_sermon_id' => null]);
        $sectionThree->update(['published_sermon_id' => null]);
        $sectionOne->update(['matched_item_id' => $items[0]->id]);
        $sectionThree->update(['expected_item_id' => $items[2]->id]);
        $items[0]->forceFill([
            'livestream_processing_id' => $processingId,
            'livestream_service_section_id' => $sectionOne->id,
        ])->save();
        $items[2]->forceFill([
            'livestream_processing_id' => $processingId,
            'livestream_service_section_id' => $sectionThree->id,
        ])->save();
        SongVideo::factory()->create([
            'song_id' => $song->id,
            'service_section_id' => $sectionOne->id,
            'church_service_id' => $service->id,
            'video_file_path' => 'shared/song.mp4',
            'duration' => 120.0,
            'recorded_date' => $date->toDateString(),
        ]);
        SongVideo::factory()->create([
            'song_id' => $song->id,
            'service_section_id' => $sectionThree->id,
            'church_service_id' => $service->id,
            'video_file_path' => 'shared/song.mp4',
            'duration' => 120.0,
            'recorded_date' => $date->toDateString(),
        ]);
        $run->loadMissing('churchService');
        $mediaGraph = app(HistoricProcessingResultInventory::class)->build($run);
        $this->seedCanaryAssets($mediaGraph);

        return $this->persistCanaryThroughRealPath($run, $service, $mediaGraph);
    }

    /**
     * Rebuild the canary graph through HistoricMediaGraphPersister so the manifest
     * describes what the real path produced rather than what a factory hand-authored.
     *
     * The persister owns the complete media graph, including its church-service links.
     *
     * @param  array<string, mixed>  $sourceGraph
     * @return array{run: MediaProcessingLog, service: ChurchService, manifest: array<string, mixed>}
     */
    private function persistCanaryThroughRealPath(
        MediaProcessingLog $sourceRun,
        ChurchService $service,
        array $sourceGraph,
    ): array {
        Storage::fake('historic_staging');
        config()->set('media-processing.storage.historic_staging_disk', 'historic_staging');
        // The real path lands imports on the quarantine disk, so the canary has
        // to read its persisted bytes back from the same faked disk.
        config()->set('media-processing.storage.historic_quarantine_disk', 'local');
        config()->set('media-processing.storage.sermon_disk', 'local');

        $targetProcessingId = self::TARGET_PROCESSING_ID;
        $targetGraph = $this->replaceProcessingKey(
            $sourceGraph,
            $sourceRun->processing_id,
            $targetProcessingId,
        );
        $assets = $this->realPathCanaryAssets($targetGraph);

        foreach ($assets as $asset) {
            $contents = Storage::disk('local')->get($asset['path']);

            if (! is_string($contents)) {
                throw new RuntimeException("Canary asset {$asset['path']} was not persisted.");
            }

            Storage::disk('historic_staging')->put($asset['path'], $contents);
        }

        $sourceSections = $sourceRun->serviceSections()->orderBy('section_order')->get();
        $sourceSermonIds = $sourceSections
            ->pluck('published_sermon_id')
            ->push($sourceRun->sermon_id)
            ->filter()
            ->unique()
            ->values();

        ServiceSection::query()
            ->whereIn('id', $sourceSections->modelKeys())
            ->update([
                'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
                'published_sermon_id' => null,
                'published_at' => null,
            ]);
        $sourceRun->update(['sermon_id' => null]);
        SermonScriptureFilter::query()->whereIn('sermon_id', $sourceSermonIds)->delete();
        Sermon::query()->whereIn('id', $sourceSermonIds)->delete();

        /**
         * Release the source run's claim on the service's canonical items too. The
         * persister refuses to take an item already bound to another run, and the
         * canary is re-persisting this service's graph rather than competing with
         * it — the same reason the publication links above are released first.
         */
        ChurchServiceItem::query()
            ->where('livestream_processing_id', $sourceRun->processing_id)
            ->update([
                'livestream_processing_id' => null,
                'livestream_service_section_id' => null,
            ]);

        $plan = new HistoricProcessingResultImportPlan(
            classification: 'create',
            reason: 'The canary exercises the real historic graph persistence path.',
            planHash: str_repeat('a', 64),
            bundleHash: str_repeat('b', 64),
            service: [
                'date' => $service->date->toDateString(),
                'service' => $service->service->value,
                'media_graph' => $targetGraph,
            ],
            assets: $assets,
        );
        $result = app(HistoricMediaGraphPersister::class)->persist($plan);
        $run = $result['processing_log'];

        $run->refresh();
        $mediaGraph = app(HistoricProcessingResultInventory::class)->build($run);
        $serviceManifest = app(HistoricNormalOutputServiceManifest::class)->build($service->fresh());

        return [
            'run' => $run,
            'service' => $service,
            'manifest' => [
                'media_graph' => $mediaGraph,
                'service_manifest' => $serviceManifest,
                /**
                 * asset_roles is the *exporter* side of the contract: the roles a
                 * bundle must carry for one physical file, which is why it is built
                 * from the pre-persist graph. Persistence deliberately does not
                 * preserve that shape — assetDestinations() allocates a distinct
                 * production path per role, so one shared file becomes one copy per
                 * role. The post-persist composition is asserted separately by
                 * persistedAssetRoles() in the canary test.
                 */
                'asset_roles' => $this->assetRoles($sourceGraph),
            ],
        ];
    }

    /**
     * The asset roles as they stand *after* persistence, for asserting what the
     * real path produced rather than what the exporter fed it.
     *
     * @return list<array{role: string, path: string, size: int, sha256: string}>
     */
    public function persistedAssetRoles(MediaProcessingLog $run): array
    {
        return $this->assetRoles(app(HistoricProcessingResultInventory::class)->build($run->fresh()));
    }

    /**
     * @param  array<string, mixed>  $graph
     * @return list<array{path: string, size: int, sha256: string, kind: string, roles: list<string>}>
     */
    private function realPathCanaryAssets(array $graph): array
    {
        /** @var array<string, array{path: string, size: int, sha256: string, kind: string, roles: list<string>}> $assets */
        $assets = [];
        $add = function (string $role, mixed $path, string $kind) use (&$assets): void {
            if (! is_string($path) || $path === '') {
                return;
            }

            $contents = Storage::disk('local')->get($path);

            if (! is_string($contents)) {
                throw new RuntimeException("Canary asset {$path} was not persisted.");
            }

            $sha256 = hash('sha256', $contents);
            $key = strlen($contents).":{$sha256}";

            if (! isset($assets[$key])) {
                $assets[$key] = [
                    'path' => $path,
                    'size' => strlen($contents),
                    'sha256' => $sha256,
                    'kind' => $kind,
                    'roles' => [],
                ];
            }

            $assets[$key]['roles'][] = $role;
        };

        foreach (HistoricProcessingResultAssetRole::RUN_FIELDS as $field) {
            $add(
                HistoricProcessingResultAssetRole::run($field),
                data_get($graph, "run.{$field}"),
                $this->canaryAssetKind($field),
            );
        }

        foreach (data_get($graph, 'metadata.service_artifacts', []) as $artifact) {
            if (is_array($artifact)) {
                $add(
                    HistoricProcessingResultAssetRole::serviceArtifact($artifact),
                    $artifact['path'] ?? null,
                    HistoricProcessingResultAssetRole::serviceArtifactKind($artifact['kind'] ?? null),
                );
            }
        }

        foreach ($graph['publications'] as $publication) {
            foreach (HistoricProcessingResultAssetRole::PUBLICATION_FIELDS as $field) {
                $add(
                    HistoricProcessingResultAssetRole::publicationField($publication['publication_key'], $field),
                    $publication[$field] ?? null,
                    $this->canaryAssetKind($field),
                );
            }

            $thumbnailMetadata = $publication['thumbnail_metadata'] ?? null;

            if (! is_array($thumbnailMetadata)) {
                continue;
            }

            foreach (HistoricProcessingResultAssetRole::THUMBNAIL_METADATA_FIELDS as $field) {
                $add(
                    HistoricProcessingResultAssetRole::publicationThumbnailMetadata(
                        $publication['publication_key'],
                        $field,
                    ),
                    $thumbnailMetadata[$field] ?? null,
                    'thumbnail',
                );
            }

            foreach (array_values($thumbnailMetadata['thumbnail_candidates'] ?? []) as $candidateIndex => $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }

                foreach (HistoricProcessingResultAssetRole::THUMBNAIL_CANDIDATE_FIELDS as $field) {
                    $add(
                        HistoricProcessingResultAssetRole::publicationThumbnailCandidate(
                            $publication['publication_key'],
                            $candidateIndex,
                            $field,
                        ),
                        $candidate[$field] ?? null,
                        'thumbnail',
                    );
                }
            }
        }

        foreach ($graph['sections'] as $section) {
            foreach (HistoricProcessingResultAssetRole::SECTION_FIELDS as $field) {
                $add(
                    HistoricProcessingResultAssetRole::section($section['section_key'], $field),
                    $section[$field] ?? null,
                    $this->canaryAssetKind($field),
                );
            }
        }

        foreach ($graph['song_videos'] as $songVideo) {
            $add(
                HistoricProcessingResultAssetRole::songVideo($songVideo['section_key']),
                $songVideo['video_file_path'] ?? null,
                'video',
            );
        }

        foreach ($assets as &$asset) {
            $asset['roles'] = array_values(array_unique($asset['roles']));
            sort($asset['roles']);
        }
        unset($asset);

        return array_values($assets);
    }

    private function canaryAssetKind(string $field): string
    {
        return match (true) {
            str_contains($field, 'audio') => 'audio',
            str_contains($field, 'video') => 'video',
            str_contains($field, 'transcript') => 'transcript',
            str_contains($field, 'thumbnail') => 'thumbnail',
            default => 'artifact',
        };
    }

    private function replaceProcessingKey(mixed $value, string $from, string $to): mixed
    {
        if (is_array($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->replaceProcessingKey($item, $from, $to),
                $value,
            );
        }

        return is_string($value) ? str_replace($from, $to, $value) : $value;
    }

    /**
     * @param  array<string, mixed>  $mediaGraph
     */
    private function seedCanaryAssets(array $mediaGraph): void
    {
        foreach (array_unique($this->assetPaths($mediaGraph)) as $path) {
            Storage::disk('local')->put($path, "canary asset contents for {$path}");
        }
    }

    /**
     * @param  array<string, mixed>  $mediaGraph
     * @return list<string>
     */
    private function assetPaths(array $mediaGraph): array
    {
        $paths = [];

        foreach (['audio_file_path', 'video_file_path', 'transcript_file_path', 'rms_log_path'] as $field) {
            if (is_string($mediaGraph['run'][$field] ?? null)) {
                $paths[] = $mediaGraph['run'][$field];
            }
        }

        foreach ($mediaGraph['publications'] as $publication) {
            foreach (['audio_file_path', 'video_file_path', 'transcript_file_path', 'thumbnail_file_path'] as $field) {
                if (is_string($publication[$field] ?? null)) {
                    $paths[] = $publication[$field];
                }
            }
        }

        foreach ($mediaGraph['sections'] as $section) {
            foreach (['extracted_video_path', 'extracted_audio_path'] as $field) {
                if (is_string($section[$field] ?? null)) {
                    $paths[] = $section[$field];
                }
            }
        }

        foreach ($mediaGraph['song_videos'] as $songVideo) {
            if (is_string($songVideo['video_file_path'] ?? null)) {
                $paths[] = $songVideo['video_file_path'];
            }
        }

        foreach ($mediaGraph['metadata']['service_artifacts'] as $artifact) {
            if (is_string($artifact['path'] ?? null)) {
                $paths[] = $artifact['path'];
            }
        }

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $mediaGraph
     * @return list<array{role: string, path: string, size: int, sha256: string}>
     */
    public function assetRoles(array $mediaGraph): array
    {
        $roles = [];
        $runFields = ['audio_file_path', 'video_file_path', 'transcript_file_path', 'rms_log_path'];

        foreach ($runFields as $field) {
            if (is_string($mediaGraph['run'][$field] ?? null)) {
                $roles[] = $this->assetRole("run.{$field}", $mediaGraph['run'][$field]);
            }
        }

        foreach ($mediaGraph['publications'] as $publication) {
            foreach (['audio_file_path', 'video_file_path', 'transcript_file_path', 'thumbnail_file_path'] as $field) {
                if (is_string($publication[$field] ?? null)) {
                    $roles[] = $this->assetRole(
                        "publication:{$publication['publication_key']}:{$field}",
                        $publication[$field],
                    );
                }
            }
        }

        foreach ($mediaGraph['sections'] as $section) {
            foreach (['extracted_video_path', 'extracted_audio_path'] as $field) {
                if (is_string($section[$field] ?? null)) {
                    $roles[] = $this->assetRole(
                        "section:{$section['section_key']}:{$field}",
                        $section[$field],
                    );
                }
            }
        }

        foreach ($mediaGraph['song_videos'] as $songVideo) {
            $roles[] = $this->assetRole(
                "song_video:{$songVideo['section_key']}:video_file_path",
                $songVideo['video_file_path'],
            );
        }

        foreach ($mediaGraph['metadata']['service_artifacts'] as $artifact) {
            $roles[] = $this->assetRole(
                "service_artifact:{$artifact['kind']}",
                $artifact['path'],
            );
        }

        return $roles;
    }

    /**
     * @return array{role: string, path: string, size: int, sha256: string}
     */
    private function assetRole(string $role, string $path): array
    {
        $contents = Storage::disk('local')->get($path);

        if (! is_string($contents)) {
            throw new RuntimeException("Canary asset {$path} was not persisted.");
        }

        return [
            'role' => $role,
            'path' => $path,
            'size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ];
    }
}
