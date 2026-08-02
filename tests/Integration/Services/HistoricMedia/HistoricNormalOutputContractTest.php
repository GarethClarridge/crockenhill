<?php

declare(strict_types=1);

namespace Tests\Integration\Services\HistoricMedia;

use App\Data\ServiceSectionMetadata;
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
use App\Models\Sermon;
use App\Models\SermonProcessingStep;
use App\Models\SermonScriptureFilter;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use App\Services\HistoricMedia\HistoricNormalOutputContract;
use App\Services\HistoricMedia\HistoricNormalOutputServiceManifest;
use App\Services\HistoricMedia\HistoricProcessingResultInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class HistoricNormalOutputContractTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_full_service_canary_has_a_stable_complete_manifest(): void
    {
        $canary = $this->createCanary();
        $contract = app(HistoricNormalOutputContract::class);

        $contract->assertCanary($canary['manifest']);

        $this->assertSame(
            $canary['manifest']['media_graph']['logical_hash'],
            app(HistoricProcessingResultInventory::class)->build($canary['run'])['logical_hash'],
        );
        $this->assertCount(3, $canary['manifest']['media_graph']['segments']);
        $this->assertCount(3, $canary['manifest']['media_graph']['sections']);
        $this->assertCount(2, $canary['manifest']['media_graph']['song_videos']);
        $this->assertSame(
            ['repeated-song', 'repeated-song'],
            array_column($canary['manifest']['media_graph']['song_videos'], 'song_canonical_key'),
        );
        $this->assertSame(
            ['observed-anchor', 'planned-only', 'observed-anchor-2'],
            array_column($canary['manifest']['service_manifest']['items'], 'canonical_identity'),
        );
        $this->assertSame(
            '2026-08-02|morning',
            $canary['manifest']['service_manifest']['service_identity'],
        );
        $this->assertSame(
            ['observed-anchor', null, 'observed-anchor-2'],
            array_column($canary['manifest']['media_graph']['sections'], 'service_item_identity'),
        );
        $this->assertSame(
            'observed-anchor',
            $canary['manifest']['media_graph']['sections'][0]['matched_item_identity'],
        );
        $this->assertSame(
            'observed-anchor-2',
            $canary['manifest']['media_graph']['sections'][2]['expected_item_identity'],
        );
        $this->assertSame(
            '2026-08-02|morning',
            $canary['manifest']['media_graph']['song_videos'][0]['church_service_identity'],
        );
        $this->assertSame(
            ChurchServiceOccurrenceState::PlannedOnly->value,
            $canary['manifest']['service_manifest']['items'][1]['occurrence_state'],
        );
        $this->assertSame(
            'preserve the complete durable item metadata',
            $canary['manifest']['service_manifest']['items'][0]['metadata']['canary_detail'],
        );
        $this->assertSame(
            'rms',
            $canary['manifest']['media_graph']['metadata']['service_artifacts'][0]['kind'],
        );
        $this->assertSame(
            SermonVideoQualityStatus::Approved->value,
            $canary['manifest']['media_graph']['publications'][0]['video_quality_status'],
        );
        $this->assertSame(
            SermonVideoVisibilityOverride::ForceShow->value,
            $canary['manifest']['media_graph']['publications'][0]['video_visibility_override'],
        );
        $this->assertSame(
            'shared/main-thumbnail.webp',
            $canary['manifest']['media_graph']['publications'][0]['thumbnail_metadata']['plain_thumbnail_path'],
        );
        $this->assertSame(
            'Canary Preacher',
            $canary['manifest']['media_graph']['publications'][0]['preacher']['name'],
        );
        $this->assertSame(
            [
                ['bible_book' => 'John', 'bible_chapter' => 3],
                ['bible_book' => 'Romans', 'bible_chapter' => 8],
            ],
            $canary['manifest']['media_graph']['publications'][0]['scripture_filters'],
        );
        $this->assertSame(
            'Canary Children Speaker',
            $canary['manifest']['media_graph']['sections'][1]['metadata']['childrens_talk_speaker']['reviewed']['preacher_name'],
        );
        $this->assertSame(
            4,
            count(array_filter(
                $canary['manifest']['asset_roles'],
                fn (array $asset): bool => $asset['path'] === 'shared/song.mp4',
            )),
        );
        $this->assertSame(
            1,
            count(array_unique(array_map(
                fn (array $asset): string => $asset['sha256'],
                array_filter(
                    $canary['manifest']['asset_roles'],
                    fn (array $asset): bool => $asset['path'] === 'shared/song.mp4',
                ),
            ))),
        );
        $this->assertNotSame(
            hash('sha256', 'shared/song.mp4'),
            array_values(array_filter(
                $canary['manifest']['asset_roles'],
                fn (array $asset): bool => $asset['path'] === 'shared/song.mp4',
            ))[0]['sha256'],
        );
    }

    #[Test]
    public function fails_when_any_durable_canary_field_or_relationship_is_unclassified(): void
    {
        $canary = $this->createCanary();
        unset($canary['manifest']['media_graph']['sections'][0]['source_segment_keys']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Required contract field media_graph.sections.0.source_segment_keys is missing.');

        app(HistoricNormalOutputContract::class)->assertCanary($canary['manifest']);
    }

    #[Test]
    public function fails_when_a_required_canary_relationship_is_missing(): void
    {
        $canary = $this->createCanary();
        unset($canary['manifest']['media_graph']['song_videos']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('media_graph has missing or unknown contract fields.');

        app(HistoricNormalOutputContract::class)->assertCanary($canary['manifest']);
    }

    #[Test]
    public function the_transport_matrix_is_versioned_and_uses_only_the_consolidated_taxonomy(): void
    {
        $contract = app(HistoricNormalOutputContract::class);
        $matrix = $contract->matrix();

        $contract->assertValidMatrix();

        $this->assertSame(HistoricNormalOutputContract::VERSION, $matrix['version']);
        $this->assertSame(['required', 'nullable'], $matrix['dimensions']['presence']);
        $this->assertSame(
            ['portable', 'deterministically_rebuilt', 'ephemeral'],
            $matrix['dimensions']['transport'],
        );
        $this->assertSame(
            ['none', 'natural_key', 'asset_path', 'local_foreign_key'],
            $matrix['dimensions']['remap'],
        );
        $this->assertNotContains('production-remapped', $matrix['dimensions']['transport']);
        $this->assertSame(
            'media_graph.sections[].source_segment_keys',
            $matrix['representations']['service_sections']['source_segment_ids'],
        );
        $this->assertSame(
            'media_graph.sections[].matched_item_identity',
            $matrix['representations']['service_sections']['matched_item_id'],
        );
        $this->assertSame(
            'nullable',
            $matrix['entities']['service_items']['fields']['canonical_identity']['presence'],
        );
        $this->assertArrayHasKey('active_position', $matrix['tables']['church_service_items']['excluded']);
        $this->assertSame(
            'required',
            $matrix['entities']['segments']['fields']['is_sermon_segment']['presence'],
        );
    }

    #[Test]
    public function nullable_values_are_preserved_in_the_normal_output(): void
    {
        $canary = $this->createCanary();
        $canary['service']->update([
            'notices' => null,
            'chapter_markers' => null,
        ]);
        $canary['manifest']['service_manifest'] = app(HistoricNormalOutputServiceManifest::class)
            ->build($canary['service']->fresh());

        $this->assertNull($canary['manifest']['service_manifest']['notices']);
        $this->assertNull($canary['manifest']['service_manifest']['chapter_markers']);
        $this->assertNull($canary['manifest']['media_graph']['sections'][0]['metadata']);

        app(HistoricNormalOutputContract::class)->assertCanary($canary['manifest']);
    }

    #[Test]
    public function every_canary_table_column_is_explicitly_classified_or_excluded(): void
    {
        $contract = app(HistoricNormalOutputContract::class);

        foreach ($contract->matrix()['tables'] as $table => $definition) {
            $classifiedColumns = array_keys($definition['classified']);
            $excludedColumns = array_keys($definition['excluded']);
            $contractColumns = [...$classifiedColumns, ...$excludedColumns];
            $schemaColumns = Schema::getColumnListing($table);

            $this->assertSame(
                [],
                array_values(array_diff($schemaColumns, $contractColumns)),
                "{$table} has schema columns missing from the normal-output contract.",
            );
            $this->assertSame(
                [],
                array_values(array_diff($contractColumns, $schemaColumns)),
                "{$table} has contract columns missing from the schema.",
            );
        }
    }

    #[Test]
    public function required_fields_cannot_be_null(): void
    {
        $canary = $this->createCanary();
        $canary['manifest']['media_graph']['sections'][0]['section_key'] = null;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Required contract field media_graph.sections.0.section_key cannot be null.');

        app(HistoricNormalOutputContract::class)->assertCanary($canary['manifest']);
    }

    #[Test]
    public function section_keys_do_not_change_when_local_item_ids_are_remapped(): void
    {
        $canary = $this->createCanary();
        $section = ServiceSection::query()
            ->where('media_processing_log_id', $canary['run']->id)
            ->where('section_order', 1)
            ->sole();
        $originalKey = $canary['manifest']['media_graph']['sections'][0]['section_key'];
        $originalItem = ChurchServiceItem::query()->findOrFail($section->church_service_item_id);
        $replacementItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $originalItem->church_service_id,
            'position' => 4,
            'type' => $originalItem->type,
            'title' => $originalItem->title,
            'canonical_identity' => $originalItem->canonical_identity,
        ]);

        $section->update(['church_service_item_id' => $replacementItem->id]);

        $remapped = app(HistoricProcessingResultInventory::class)->build($canary['run']->fresh());

        $this->assertSame($originalKey, $remapped['sections'][0]['section_key']);
    }

    #[Test]
    public function section_keys_distinguish_resolved_childrens_talk_speakers_without_using_local_ids(): void
    {
        $canary = $this->createCanary();
        $section = ServiceSection::query()
            ->where('media_processing_log_id', $canary['run']->id)
            ->where('section_order', 2)
            ->sole();
        $originalKey = $canary['manifest']['media_graph']['sections'][1]['section_key'];
        $metadata = $section->metadata?->toArray() ?? [];
        $metadata['childrens_talk_speaker']['reviewed']['preacher_name'] = 'Different Canary Speaker';
        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        $section->save();

        $changed = app(HistoricProcessingResultInventory::class)->build($canary['run']->fresh());

        $this->assertNotSame($originalKey, $changed['sections'][1]['section_key']);
    }

    #[Test]
    public function unlinked_preachers_do_not_create_a_canonical_identity_from_the_display_cache(): void
    {
        $sermon = Sermon::factory()->create([
            'preacher' => 'Mark Drury',
            'preacher_id' => null,
        ]);
        $run = MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id]);

        $inventory = app(HistoricProcessingResultInventory::class)->build($run);

        $this->assertNull($inventory['publications'][0]['preacher']);
        $this->assertSame('Mark Drury', $inventory['publications'][0]['preacher_display_name']);
        app(HistoricNormalOutputContract::class)->assertInventory($inventory);
    }

    /**
     * @return array{run: MediaProcessingLog, service: ChurchService, manifest: array<string, mixed>}
     */
    private function createCanary(): array
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
        $processingId = '00000000-0000-0000-0000-000000000001';
        $mainSermon = Sermon::factory()->create([
            'date' => $date->toDateString(),
            'service' => SermonService::Morning,
            'content_type' => SermonContentType::Sermon,
            'title' => 'Canary sermon',
            'slug' => 'canary-sermon',
            'reference' => null,
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
                ],
            ],
            'sermon_id' => $mainSermon->id,
            'church_service_id' => $service->id,
            'started_at' => $date->copy()->subHour(),
            'completed_at' => $date,
            'is_degraded_completion' => false,
        ]);
        $mainSermon->update(['livestream_processing_id' => $processingId]);
        foreach ([
            ['sermon_id' => $mainSermon->id, 'bible_book' => 'John', 'bible_chapter' => 3],
            ['sermon_id' => $mainSermon->id, 'bible_book' => 'Romans', 'bible_chapter' => 8],
        ] as $filter) {
            SermonScriptureFilter::query()->create($filter);
        }
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
        $serviceManifest = app(HistoricNormalOutputServiceManifest::class)->build($service);
        $this->seedCanaryAssets($mediaGraph);

        return [
            'run' => $run,
            'service' => $service,
            'manifest' => [
                'media_graph' => $mediaGraph,
                'service_manifest' => $serviceManifest,
                'asset_roles' => $this->assetRoles($mediaGraph),
            ],
        ];
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
    private function assetRoles(array $mediaGraph): array
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
