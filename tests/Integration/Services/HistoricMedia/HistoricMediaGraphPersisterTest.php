<?php

declare(strict_types=1);

namespace Tests\Integration\Services\HistoricMedia;

use App\Data\HistoricProcessingResultImportPlan;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonContentType;
use App\Enums\SermonPublicationState;
use App\Enums\SermonService;
use App\Enums\SermonSourceType;
use App\Enums\SermonVideoQualityStatus;
use App\Enums\SermonVideoVisibilityOverride;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Services\HistoricMedia\HistoricMediaGraphPersister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class HistoricMediaGraphPersisterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_the_run_before_linked_publications(): void
    {
        $processingId = '00000000-0000-0000-0000-000000000011';
        $plan = $this->planWithGraph(
            processingId: $processingId,
            publications: [$this->publication()],
        );

        $result = app(HistoricMediaGraphPersister::class)->persist($plan);
        $sermon = Sermon::query()->where('slug', 'persister-test-sermon')->sole();

        $this->assertSame($processingId, $sermon->livestream_processing_id);
        $this->assertSame(SermonPublicationState::Quarantined, $sermon->publication_state);
        $this->assertSame('historic_quarantine', $sermon->asset_disk);
        $this->assertSame($sermon->id, $result['processing_log']->sermon_id);
        $this->assertDatabaseHas('media_processing_logs', [
            'processing_id' => $processingId,
        ]);
    }

    #[Test]
    public function it_reuses_and_enriches_a_publication_with_the_same_source_hash(): void
    {
        $preacher = Preacher::factory()->create([
            'name' => 'Persister Test Preacher',
            'slug' => 'persister-test-preacher',
        ]);
        $previousRun = MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => 'previous-processing',
            'file_hash' => str_repeat('c', 64),
            'sermon_id' => null,
        ]);
        $existing = Sermon::factory()->create([
            'date' => '2026-08-02',
            'service' => SermonService::Morning,
            'content_type' => SermonContentType::Sermon,
            'slug' => 'persister-test-sermon',
            'title' => 'Persister test sermon',
            'reference' => null,
            'preacher' => $preacher->name,
            'preacher_id' => $preacher->id,
            'series' => null,
            'summary' => 'Existing richer summary',
            'points' => null,
            'show_summary' => false,
            'show_points' => false,
            'source_type' => SermonSourceType::Livestream,
            'video_quality_status' => SermonVideoQualityStatus::Unassessed,
            'video_visibility_override' => SermonVideoVisibilityOverride::Default,
            'livestream_processing_id' => 'previous-processing',
        ]);
        $previousRun->update(['sermon_id' => $existing->id]);

        $plan = $this->planWithGraph(
            processingId: '00000000-0000-0000-0000-000000000015',
            publications: [$this->publication()],
        );

        $result = app(HistoricMediaGraphPersister::class)->persist($plan);

        $this->assertSame($existing->id, $result['processing_log']->sermon_id);
        $this->assertSame(1, Sermon::query()->where('slug', 'persister-test-sermon')->count());
        $this->assertSame('Existing richer summary', $existing->fresh()->summary);
        $this->assertNotNull($existing->fresh()->duration);
    }

    /**
     * A canonical item already bound to a live run is production data, not a free
     * slot. Re-pointing it at the historic run would silently orphan the section
     * the current-era pipeline extracted for it.
     */
    #[Test]
    public function it_refuses_to_steal_a_service_item_link_from_another_run(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-08-02',
            'service' => 'morning',
        ]);
        $liveRun = MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => 'live-weekly-run',
            'church_service_id' => $service->id,
        ]);
        $liveSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $liveRun->id,
            'church_service_item_id' => null,
            'section_order' => 1,
            'source_segment_ids' => [],
        ]);
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'canonical_identity' => 'canonical-item-1',
            'livestream_processing_id' => $liveRun->processing_id,
            'livestream_service_section_id' => $liveSection->id,
        ]);

        $plan = $this->planWithGraph(
            processingId: '00000000-0000-0000-0000-000000000016',
            sections: [$this->section('claimed-section', [
                'service_item_identity' => 'canonical-item-1',
                'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
                'extracted_video_path' => null,
                'extracted_audio_path' => null,
                'extracted_at' => null,
                'published_at' => null,
            ])],
        );

        $exception = null;

        try {
            app(HistoricMediaGraphPersister::class)->persist($plan);
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertStringContainsString('canonical-item-1', $exception->getMessage());
        $this->assertStringContainsString('live-weekly-run', $exception->getMessage());

        $item->refresh();
        $this->assertSame('live-weekly-run', $item->livestream_processing_id);
        $this->assertSame($liveSection->id, $item->livestream_service_section_id);
    }

    #[Test]
    public function it_persists_the_reviewed_scripture_filter_inventory_explicitly(): void
    {
        $plan = $this->planWithGraph(
            processingId: '00000000-0000-0000-0000-000000000013',
            publications: [$this->publication(
                reference: 'John 3',
                scriptureFilters: [['bible_book' => 'Jude', 'bible_chapter' => 1]],
            )],
        );

        app(HistoricMediaGraphPersister::class)->persist($plan);
        $sermon = Sermon::query()->where('slug', 'persister-test-sermon')->sole();

        $this->assertSame(
            [['bible_book' => 'Jude', 'bible_chapter' => 1]],
            $sermon->scriptureFilters()
                ->orderBy('bible_book')
                ->get(['bible_book', 'bible_chapter'])
                ->map(fn ($filter): array => [
                    'bible_book' => $filter->bible_book,
                    'bible_chapter' => $filter->bible_chapter,
                ])
                ->all(),
        );
    }

    #[Test]
    public function convergence_is_event_quiet_and_does_not_backfill_an_unrelated_alias_match(): void
    {
        $unrelated = Sermon::factory()->create([
            'preacher' => 'machine alias',
            'preacher_id' => null,
        ]);
        $publication = $this->publication(
            reference: 'John 3',
            scriptureFilters: [['bible_book' => 'John', 'bible_chapter' => 3]],
        );
        $publication['preacher']['aliases'] = ['machine alias'];
        Event::fakeFor(function () use ($publication): void {
            app(HistoricMediaGraphPersister::class)->persist($this->planWithGraph(
                processingId: '00000000-0000-0000-0000-000000000023',
                publications: [$publication],
            ));

            Event::assertNothingDispatched();
        });
        $this->assertNull($unrelated->fresh()?->preacher_id);
        $this->assertDatabaseHas('preacher_aliases', ['alias' => 'machine alias']);
        $this->assertDatabaseHas('sermon_scripture_filters', [
            'sermon_id' => Sermon::query()->where('slug', 'persister-test-sermon')->value('id'),
            'bible_book' => 'John',
            'bible_chapter' => 3,
        ]);
    }

    #[Test]
    public function it_relinks_a_scripture_passage_by_portable_natural_key(): void
    {
        $destinationPassage = ScripturePassage::factory()->create([
            'bible_id' => 'destination-bible',
            'normalized_reference' => 'John 3:16',
        ]);
        $publication = [
            ...$this->publication(reference: 'John 3:16'),
            'scripture_passage' => [
                'bible_id' => 'destination-bible',
                'normalized_reference' => 'John 3:16',
            ],
            'scripture_passage_outcome' => ['status' => 'linked'],
        ];

        app(HistoricMediaGraphPersister::class)->persist($this->planWithGraph(
            processingId: '00000000-0000-0000-0000-000000000019',
            publications: [$publication],
        ));

        $sermon = Sermon::query()->where('slug', 'persister-test-sermon')->sole();
        $this->assertSame($destinationPassage->id, $sermon->scripture_passage_id);
        $this->assertArrayNotHasKey('scripture_passage_id', $publication);
    }

    /**
     * F44: `occasion` has no column on any model, so the destination's processing
     * metadata is the only place the curated fact can land. Losing it here means
     * the one-time import destroyed a fact that cannot be re-derived.
     */
    #[Test]
    public function it_lands_curated_editorial_facts_on_the_destination_run(): void
    {
        app(HistoricMediaGraphPersister::class)->persist($this->planWithGraph(
            processingId: '00000000-0000-0000-0000-000000000031',
            publications: [$this->publication()],
            metadata: [
                'historic_import' => [
                    'tag' => 'livestream',
                    'editorial_facts' => [
                        'occasion' => 'Harvest Thanksgiving',
                        'title' => 'The God Who Provides',
                        'speaker' => 'Alan Brown',
                        'scripture_reference' => 'Ruth 2:1-23',
                        'series' => 'Ruth',
                    ],
                ],
            ],
        ));

        $run = MediaProcessingLog::query()
            ->where('processing_id', '00000000-0000-0000-0000-000000000031')
            ->sole();

        $this->assertSame(
            'Harvest Thanksgiving',
            $run->processing_metadata?->editorialFacts?->occasion,
        );
        $this->assertSame(
            'Ruth 2:1-23',
            $run->processing_metadata?->editorialFacts?->scriptureReference,
        );
    }

    #[Test]
    public function it_names_the_section_when_a_published_state_has_no_extraction_media(): void
    {
        Storage::fake('historic_staging');
        Storage::fake('local');
        config()->set('media-processing.storage.historic_staging_disk', 'historic_staging');
        config()->set('media-processing.storage.historic_quarantine_disk', 'local');
        config()->set('media-processing.storage.sermon_disk', 'local');

        $sectionKey = 'unbacked-section';
        $plan = $this->planWithGraph(
            processingId: '00000000-0000-0000-0000-000000000014',
            sections: [$this->section($sectionKey, [
                'extracted_video_path' => null,
                'extracted_audio_path' => null,
                'extracted_at' => null,
            ])],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "Section {$sectionKey} cannot become published without extracted_video_path, extracted_at, extracted_audio_path."
        );

        app(HistoricMediaGraphPersister::class)->persist($plan);
    }

    #[Test]
    public function it_transitions_a_section_to_published_only_after_required_media_exists(): void
    {
        Storage::fake('historic_staging');
        Storage::fake('local');
        config()->set('media-processing.storage.historic_staging_disk', 'historic_staging');
        config()->set('media-processing.storage.historic_quarantine_disk', 'local');
        config()->set('media-processing.storage.sermon_disk', 'local');

        $video = 'persister section video';
        $audio = 'persister section audio';
        Storage::disk('historic_staging')->put('staged/section.mp4', $video);
        Storage::disk('historic_staging')->put('staged/section.mp3', $audio);

        $sectionKey = 'persister-section';
        $plan = $this->planWithGraph(
            processingId: '00000000-0000-0000-0000-000000000012',
            publications: [$this->publication($sectionKey)],
            sections: [$this->section($sectionKey)],
            assets: [
                [
                    'path' => 'staged/section.mp4',
                    'size' => strlen($video),
                    'sha256' => hash('sha256', $video),
                    'kind' => 'video',
                    'roles' => ["section:{$sectionKey}:extracted_video_path"],
                ],
                [
                    'path' => 'staged/section.mp3',
                    'size' => strlen($audio),
                    'sha256' => hash('sha256', $audio),
                    'kind' => 'audio',
                    'roles' => ["section:{$sectionKey}:extracted_audio_path"],
                ],
            ],
        );

        $result = app(HistoricMediaGraphPersister::class)->persist($plan);
        $section = ServiceSection::query()->sole();

        $this->assertSame(ServiceSectionPublicationStatus::Published, $section->publication_status);
        $this->assertSame(
            "sermons/sections/{$section->id}/video.mp4",
            $section->extracted_video_path,
        );
        $this->assertSame(
            "sermons/sections/{$section->id}/audio.mp3",
            $section->extracted_audio_path,
        );
        $this->assertNotNull($section->extracted_at);
        $this->assertNotNull($section->published_at);
        $this->assertSame($section->published_sermon_id, $result['processing_log']->serviceSections()->sole()->published_sermon_id);
    }

    #[Test]
    public function it_allocates_and_applies_thumbnail_metadata_and_candidate_roles_without_collisions(): void
    {
        $sermon = Sermon::factory()->create([
            'thumbnail_metadata' => [
                'thumbnail_candidates' => [[
                    'id' => 'candidate-1',
                    'timestamp' => 12.0,
                    'score' => 0.9,
                    'plain_path' => 'staged/candidate-plain.webp',
                ]],
            ],
        ]);
        $run = MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => 'thumbnail-role-run',
            'processing_metadata' => [],
        ]);
        $plan = new HistoricProcessingResultImportPlan(
            classification: 'create',
            reason: 'test',
            planHash: str_repeat('a', 64),
            bundleHash: str_repeat('b', 64),
            service: ['media_graph' => ['processing_key' => 'thumbnail-role-run']],
            assets: [
                [
                    'path' => 'staged/plain.webp',
                    'size' => 5,
                    'sha256' => hash('sha256', 'plain'),
                    'kind' => 'thumbnail',
                    'roles' => ['publication:thumbnail-role-run:main:sermon:thumbnail_metadata:plain_thumbnail_path'],
                ],
                [
                    'path' => 'staged/candidate-plain.webp',
                    'size' => 14,
                    'sha256' => hash('sha256', 'candidate plain'),
                    'kind' => 'thumbnail',
                    'roles' => ['publication:thumbnail-role-run:main:sermon:thumbnail_candidate:0:plain_path'],
                ],
            ],
        );
        $persister = app(HistoricMediaGraphPersister::class);
        $allocate = new ReflectionMethod($persister, 'assetDestinations');
        $apply = new ReflectionMethod($persister, 'applyAllocatedPaths');

        /** @var array<string, string> $destinations */
        $destinations = $allocate->invoke($persister, $plan, $run, [], ['main' => $sermon]);

        $this->assertSame("sermons/{$sermon->id}/thumbnail-plain.webp", $destinations['publication:thumbnail-role-run:main:sermon:thumbnail_metadata:plain_thumbnail_path']);
        $this->assertSame("sermons/{$sermon->id}/thumbnail-candidate-0-plain.webp", $destinations['publication:thumbnail-role-run:main:sermon:thumbnail_candidate:0:plain_path']);
        $this->assertNotSame(
            $destinations['publication:thumbnail-role-run:main:sermon:thumbnail_metadata:plain_thumbnail_path'],
            $destinations['publication:thumbnail-role-run:main:sermon:thumbnail_candidate:0:plain_path'],
        );

        $apply->invoke($persister, $plan, $run, [], ['main' => $sermon], $destinations);
        $metadata = Sermon::query()->findOrFail($sermon->id)->thumbnail_metadata;

        $this->assertSame('sermons/'.$sermon->id.'/thumbnail-plain.webp', $metadata?->plainThumbnailPath);
        $this->assertSame(
            'sermons/'.$sermon->id.'/thumbnail-candidate-0-plain.webp',
            $metadata?->thumbnailCandidates[0]['plain_path'],
        );
    }

    #[Test]
    public function it_remaps_a_service_artifact_that_records_no_kind(): void
    {
        $artifactHash = hash('sha256', 'rms log');
        $run = MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => 'artifact-role-run',
            'processing_metadata' => [
                'service_artifacts' => [[
                    'path' => 'staged/rms.log',
                    'sha256' => $artifactHash,
                ]],
            ],
        ]);
        /** The literal role the exporter emits for an artifact with no kind. */
        $role = "service_artifact:artifact:{$artifactHash}";
        $plan = $this->plan('artifact-role-run', [[
            'path' => 'staged/rms.log',
            'size' => 7,
            'sha256' => $artifactHash,
            'kind' => 'artifact',
            'roles' => [$role],
        ]]);
        $persister = app(HistoricMediaGraphPersister::class);

        $destinations = $this->invoke($persister, 'assetDestinations', [$plan, $run, [], []]);
        $this->invoke($persister, 'applyAllocatedPaths', [$plan, $run, [], [], $destinations]);

        $this->assertSame('service-transcripts/artifact-role-run/rms.log', $destinations[$role]);
        $this->assertSame(
            'service-transcripts/artifact-role-run/rms.log',
            MediaProcessingLog::query()->findOrFail($run->id)->processing_metadata?->toArray()['service_artifacts'][0]['path'],
        );
    }

    #[Test]
    public function it_refuses_to_allocate_one_destination_to_two_different_contents(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => 'collision-run',
            'processing_metadata' => [],
        ]);
        $plan = $this->plan('collision-run', [
            [
                'path' => 'staged/first/notes.txt',
                'size' => 5,
                'sha256' => hash('sha256', 'first'),
                'kind' => 'transcript',
                'roles' => ['run_transcript_file_path'],
            ],
            [
                'path' => 'staged/second/notes.txt',
                'size' => 6,
                'sha256' => hash('sha256', 'second'),
                'kind' => 'artifact',
                'roles' => ['service_artifact:rms:'.hash('sha256', 'second')],
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already allocated to different content');

        $this->invoke(app(HistoricMediaGraphPersister::class), 'assetDestinations', [$plan, $run, [], []]);
    }

    #[Test]
    public function it_allows_shared_content_to_reuse_one_destination(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => 'shared-run',
            'processing_metadata' => [],
        ]);
        $sharedHash = hash('sha256', 'shared');
        $plan = $this->plan('shared-run', [[
            'path' => 'staged/full-service.mp3',
            'size' => 6,
            'sha256' => $sharedHash,
            'kind' => 'audio',
            'roles' => ['run_audio_file_path', 'service_artifact:audio:'.$sharedHash],
        ]]);

        $destinations = $this->invoke(
            app(HistoricMediaGraphPersister::class),
            'assetDestinations',
            [$plan, $run, [], []],
        );

        $this->assertSame(
            $destinations['run_audio_file_path'],
            $destinations['service_artifact:audio:'.$sharedHash],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $assets
     */
    private function plan(string $processingKey, array $assets): HistoricProcessingResultImportPlan
    {
        return new HistoricProcessingResultImportPlan(
            classification: 'create',
            reason: 'test',
            planHash: str_repeat('a', 64),
            bundleHash: str_repeat('b', 64),
            service: ['media_graph' => ['processing_key' => $processingKey]],
            assets: $assets,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $publications
     * @param  list<array<string, mixed>>  $sections
     * @param  list<array<string, mixed>>  $assets
     */
    private function planWithGraph(
        string $processingId,
        array $publications = [],
        array $sections = [],
        array $assets = [],
        array $metadata = [],
    ): HistoricProcessingResultImportPlan {
        $date = '2026-08-02';

        return new HistoricProcessingResultImportPlan(
            classification: 'create',
            reason: 'test',
            planHash: str_repeat('a', 64),
            bundleHash: str_repeat('b', 64),
            service: [
                'media_graph' => [
                    'processing_key' => $processingId,
                    'run' => [
                        'processing_id' => $processingId,
                        'processing_type' => MediaType::Livestream->value,
                        'status' => ProcessingStatus::Completed->value,
                        'current_step' => 'completed',
                        'original_filename' => 'persister-test.mp4',
                        'file_hash' => str_repeat('c', 64),
                        'file_size' => 100,
                        'duration' => 60.0,
                        'extracted_date' => $date,
                        'extracted_service' => SermonService::Morning->value,
                        'audio_file_path' => null,
                        'video_file_path' => null,
                        'transcript_file_path' => null,
                        'rms_log_path' => null,
                        'sermon_start_time' => null,
                        'sermon_end_time' => null,
                        'threshold_method' => null,
                        'adaptive_threshold' => null,
                        'rms_stats' => null,
                        'started_at' => "{$date}T10:00:00+00:00",
                        'completed_at' => "{$date}T11:00:00+00:00",
                        'is_degraded_completion' => false,
                    ],
                    'metadata' => $metadata,
                    'logical_hash' => str_repeat('d', 64),
                    'steps' => [],
                    'segments' => [],
                    'sections' => $sections,
                    'publications' => $publications,
                    'song_videos' => [],
                ],
            ],
            assets: $assets,
        );
    }

    /**
     * A published sermon section, which the publication-media CHECK constraint
     * requires to carry both extraction paths and an extraction timestamp.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function section(string $sectionKey, array $overrides = []): array
    {
        return [
            'section_key' => $sectionKey,
            'section_order' => 1,
            'section_type' => ServiceSectionType::Sermon->value,
            'title' => 'Persisted section',
            'summary' => null,
            'start_time' => 0.0,
            'end_time' => 60.0,
            'duration' => 60.0,
            'confidence' => 1.0,
            'status' => ServiceSectionStatus::Identified->value,
            'needs_manual_review' => false,
            'source_segment_keys' => [],
            'metadata' => null,
            'song_match_type' => null,
            'publication_status' => ServiceSectionPublicationStatus::Published->value,
            'extracted_video_path' => 'staged/section.mp4',
            'extracted_audio_path' => 'staged/section.mp3',
            'extracted_at' => '2026-08-02T11:00:00+00:00',
            'published_at' => '2026-08-02T11:05:00+00:00',
            'unpublished_expires_at' => null,
            ...$overrides,
        ];
    }

    /**
     * @param  list<array{bible_book: string, bible_chapter: int}>  $scriptureFilters
     * @return array<string, mixed>
     */
    private function publication(
        ?string $sectionKey = null,
        ?string $reference = null,
        array $scriptureFilters = [],
    ): array {
        return [
            'section_key' => $sectionKey,
            'content_type' => SermonContentType::Sermon->value,
            'date' => '2026-08-02',
            'service' => SermonService::Morning->value,
            'slug' => $sectionKey === null ? 'persister-test-sermon' : 'persister-test-section-sermon',
            'filetype' => 'mp3',
            'title' => $sectionKey === null ? 'Persister test sermon' : 'Persister test section sermon',
            'reference' => $reference,
            'series' => null,
            'summary' => null,
            'meta_description' => null,
            'points' => null,
            'show_summary' => false,
            'show_points' => false,
            'duration' => 60.0,
            'segment_start_time' => null,
            'segment_end_time' => null,
            'preacher_source' => null,
            'preacher_confidence' => null,
            'needs_preacher_review' => false,
            'source_type' => SermonSourceType::Livestream->value,
            'video_quality_status' => SermonVideoQualityStatus::Unassessed->value,
            'video_quality_reason' => null,
            'video_visibility_override' => SermonVideoVisibilityOverride::Default->value,
            'video_quality_assessed_at' => null,
            'thumbnail_generated_at' => null,
            'thumbnail_metadata' => null,
            'preacher' => [
                'name' => 'Persister Test Preacher',
                'slug' => 'persister-test-preacher',
                'aliases' => [],
            ],
            'scripture_filters' => $scriptureFilters,
        ];
    }

    /** @param list<mixed> $arguments */
    private function invoke(object $target, string $method, array $arguments): mixed
    {
        return (new ReflectionMethod($target, $method))->invokeArgs($target, $arguments);
    }
}
