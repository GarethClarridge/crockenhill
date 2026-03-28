<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\SermonService;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Events\ChurchServiceCanonicalListChanged;
use App\Jobs\AlignWithOos;
use App\Jobs\ClassifyServiceSections;
use App\Jobs\ClassifySpeechSections;
use App\Jobs\EnhanceAudio;
use App\Jobs\ExtractSermon;
use App\Jobs\GenerateThumbnail;
use App\Jobs\IdentifySpeaker;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\ProjectLivestreamServiceStructure;
use App\Jobs\ReconcileServiceSections;
use App\Jobs\SubmitToProcessing;
use App\Jobs\TranscribeAudio;
use App\Jobs\TranscribeSpeechSegments;
use App\Livewire\Admin\ChurchServices\ListChurchServices;
use App\Livewire\Admin\ChurchServices\ManageChurchService;
use App\Livewire\Admin\ChurchServices\ShowChurchService;
use App\Livewire\Admin\ChurchServices\UploadChurchService;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\SermonProcessingStep;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\User;
use App\Support\ChurchServiceProcessingTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OpenLpArchiveFactory;
use Tests\TestCase;
use Tests\Traits\BuildsTestScenarios;

class AdminChurchServiceTest extends TestCase
{
    use BuildsTestScenarios;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createVerifiedAdmin();
    }

    #[Test]
    public function admin_can_view_and_filter_services_in_list(): void
    {
        $this->actingAs($this->admin);

        $this->churchServiceScenario()
            ->openLp()
            ->state([
                'date' => '2026-01-12',
                'service' => SermonService::MORNING,
                'original_filename' => '2026-01-12 AM.osz',
            ])
            ->create();

        $this->churchServiceScenario()
            ->openLp()
            ->needsReview()
            ->state([
                'date' => '2026-01-19',
                'service' => SermonService::EVENING,
                'original_filename' => '2026-01-19 PM.osz',
            ])
            ->create();

        Livewire::test(ListChurchServices::class)
            ->assertSee('Services')
            ->set('serviceFilter', SermonService::EVENING->value)
            ->assertSee('2026-01-19 PM.osz')
            ->assertDontSee('2026-01-12 AM.osz')
            ->set('serviceFilter', null)
            ->set('needsReviewFilter', true)
            ->assertSee('2026-01-19 PM.osz')
            ->assertDontSee('2026-01-12 AM.osz');
    }

    #[Test]
    public function list_resets_invalid_sort_input_to_safe_defaults(): void
    {
        $this->actingAs($this->admin);

        $this->churchServiceScenario()
            ->state([
                'date' => '2026-02-01',
                'original_filename' => '2026-02-01 AM.osz',
            ])
            ->create();

        Livewire::test(ListChurchServices::class)
            ->set('sortBy', 'invalid_column')
            ->set('sortDirection', 'sideways')
            ->assertSet('sortBy', 'date')
            ->assertSet('sortDirection', 'desc')
            ->assertSee('2026-02-01 AM.osz');
    }

    #[Test]
    public function admin_can_upload_openlp_archive_from_livewire_ui(): void
    {
        $this->actingAs($this->admin);

        $openLpUpload = OpenLpArchiveFactory::makeUpload(
            archiveName: '2024-11-17 AM.osz',
            osjName: '2024-11-17 AM.osj',
            payload: OpenLpArchiveFactory::payload([
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::songHeader('Song One', 'song one@')
                ),
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::customHeader('Notices')
                ),
            ]),
        );

        $upload = $this->makeLivewireUpload($openLpUpload, '2024-11-17 AM.osz', 'application/zip');

        $component = Livewire::test(UploadChurchService::class)
            ->set('file', $upload)
            ->call('save');

        $service = ChurchService::query()->firstOrFail();

        $component->assertRedirect(route('admin.services.show', $service));

        $this->assertDatabaseHas('church_services', [
            'id' => $service->id,
            'date' => '2024-11-17',
            'service' => SermonService::MORNING->value,
            'source' => 'openlp',
            'original_filename' => '2024-11-17 AM.osz',
        ]);
        $this->assertDatabaseCount('church_service_items', 2);
    }

    #[Test]
    public function upload_component_links_song_items_to_catalog_after_import(): void
    {
        $this->actingAs($this->admin);

        $song = Song::factory()->create([
            'canonical_key' => 'song one',
            'title' => 'Song One Canonical',
        ]);

        $openLpUpload = OpenLpArchiveFactory::makeUpload(
            archiveName: '2024-11-17 AM.osz',
            osjName: '2024-11-17 AM.osj',
            payload: OpenLpArchiveFactory::payload([
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::songHeader('Song One', 'song one@')
                ),
            ]),
        );

        $upload = $this->makeLivewireUpload($openLpUpload, '2024-11-17 AM.osz', 'application/zip');

        Livewire::test(UploadChurchService::class)
            ->set('file', $upload)
            ->call('save');

        $this->assertDatabaseHas('church_service_items', [
            'openlp_search_title' => 'song one@',
            'song_id' => $song->id,
        ]);
    }

    #[Test]
    public function upload_component_requires_a_file(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(UploadChurchService::class)
            ->call('save')
            ->assertHasErrors(['file' => ['required']]);
    }

    #[Test]
    public function admin_can_create_a_manual_service_with_mixed_item_types(): void
    {
        $this->actingAs($this->admin);

        $song = Song::factory()->create([
            'title' => 'Blessed Assurance',
            'canonical_key' => 'blessed assurance',
        ]);

        $component = Livewire::test(ManageChurchService::class)
            ->set('date', '2026-05-03')
            ->set('service', SermonService::MORNING->value)
            ->set('items.0.section_type', ServiceSectionType::WELCOME->value)
            ->set('items.0.title', 'Welcome and Call to Worship')
            ->call('addItem')
            ->set('items.1.section_type', ServiceSectionType::SONG->value)
            ->set('items.1.title', 'Blessed')
            ->assertSee('Blessed Assurance')
            ->call('selectSong', 1, $song->id)
            ->call('addItem')
            ->set('items.2.section_type', ServiceSectionType::BIBLE_READING->value)
            ->set('items.2.title', 'John 3:16-21')
            ->call('save');

        $service = ChurchService::query()
            ->with(['items' => fn ($query) => $query->orderBy('position')->orderBy('id')])
            ->firstOrFail();

        $component->assertRedirect(route('admin.services.show', $service));

        $this->assertSame('manual', $service->source);
        $this->assertFalse($service->needs_review);
        $this->assertSame($this->admin->id, $service->import_metadata['manual_edit']['saved_by_user_id'] ?? null);
        $this->assertSame(3, $service->import_metadata['manual_edit']['item_count'] ?? null);

        $this->assertCount(3, $service->items);
        $this->assertSame('custom', $service->items[0]->type);
        $this->assertSame(ServiceSectionType::WELCOME, $service->items[0]->section_type);
        $this->assertArrayNotHasKey('section_type', $service->items[0]->metadata ?? []);
        $this->assertSame('songs', $service->items[1]->type);
        $this->assertSame($song->id, $service->items[1]->song_id);
        $this->assertSame('blessed assurance', $service->items[1]->metadata['linked_song_canonical_key'] ?? null);
        $this->assertSame('bibles', $service->items[2]->type);
        $this->assertSame(ServiceSectionType::BIBLE_READING, $service->items[2]->section_type);
        $this->assertArrayNotHasKey('section_type', $service->items[2]->metadata ?? []);
    }

    #[Test]
    public function manual_save_emits_one_canonical_list_changed_event(): void
    {
        Event::fake([ChurchServiceCanonicalListChanged::class]);
        $this->actingAs($this->admin);

        Livewire::test(ManageChurchService::class)
            ->set('date', '2026-05-03')
            ->set('service', SermonService::MORNING->value)
            ->set('items.0.section_type', ServiceSectionType::WELCOME->value)
            ->set('items.0.title', 'Welcome and Call to Worship')
            ->call('save');

        $service = ChurchService::query()->firstOrFail();

        Event::assertDispatched(
            ChurchServiceCanonicalListChanged::class,
            fn (ChurchServiceCanonicalListChanged $event): bool => $event->churchServiceId === $service->id
                && $event->source === 'manual'
                && count($event->changes) > 0
        );
        Event::assertDispatchedTimes(ChurchServiceCanonicalListChanged::class, 1);
    }

    #[Test]
    public function admin_can_edit_a_manual_service_and_reorder_add_and_remove_items(): void
    {
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'date' => '2026-05-10',
            'service' => SermonService::EVENING,
            'source' => 'openlp',
            'original_filename' => '2026-05-10 PM.osz',
            'needs_review' => true,
            'import_metadata' => [
                'confidence_score' => 1.0,
                'warnings' => [],
            ],
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'custom',
            'source' => 'manual',
            'title' => 'Welcome',
            'source_title' => 'Welcome',
            'openlp_search_title' => null,
            'metadata' => ['section_type' => ServiceSectionType::WELCOME->value],
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 2,
            'type' => 'custom',
            'title' => 'Opening Prayer',
            'metadata' => ['section_type' => ServiceSectionType::PRAYER->value],
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 3,
            'type' => 'custom',
            'title' => 'Sermon',
            'metadata' => ['section_type' => ServiceSectionType::SERMON->value],
        ]);

        $song = Song::factory()->create([
            'title' => 'Closing Song',
            'canonical_key' => 'closing song',
        ]);

        $component = Livewire::test(ManageChurchService::class, ['churchService' => $service])
            ->assertSet('items.0.section_type', ServiceSectionType::WELCOME->value)
            ->assertSet('items.1.section_type', ServiceSectionType::PRAYER->value)
            ->call('moveItemDown', 0)
            ->call('removeItem', 2)
            ->set('items.1.title', 'Welcome and Notices')
            ->call('addItem')
            ->set('items.2.section_type', ServiceSectionType::SONG->value)
            ->set('items.2.title', 'Closing')
            ->assertSee('Closing Song')
            ->call('selectSong', 2, $song->id)
            ->call('save');

        $service->refresh();
        $service->load(['items' => fn ($query) => $query->orderBy('position')->orderBy('id')]);

        $component->assertRedirect(route('admin.services.show', $service));

        $this->assertSame('manual', $service->source);
        $this->assertFalse($service->needs_review);
        $this->assertSame('2026-05-10 PM.osz', $service->original_filename);
        $this->assertSame(3, $service->items->count());
        $this->assertSame('Opening Prayer', $service->items[0]->title);
        $this->assertSame(ServiceSectionType::PRAYER, $service->items[0]->section_type);
        $this->assertArrayNotHasKey('section_type', $service->items[0]->metadata ?? []);
        $this->assertSame('Welcome and Notices', $service->items[1]->title);
        $this->assertSame(ServiceSectionType::WELCOME, $service->items[1]->section_type);
        $this->assertArrayNotHasKey('section_type', $service->items[1]->metadata ?? []);
        $this->assertSame('Closing Song', $service->items[2]->title);
        $this->assertSame($song->id, $service->items[2]->song_id);
        $this->assertDatabaseMissing('church_service_items', [
            'church_service_id' => $service->id,
            'title' => 'Sermon',
            'deleted_at' => null,
        ]);
    }

    #[Test]
    public function manual_item_edits_dispatch_reconciliation_for_matching_processed_livestreams(): void
    {
        Queue::fake();
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'date' => '2026-05-17',
            'service' => SermonService::MORNING,
            'source' => 'manual',
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'custom',
            'source' => 'manual',
            'title' => 'Welcome',
            'source_title' => 'Welcome',
            'openlp_search_title' => null,
            'metadata' => ['section_type' => ServiceSectionType::WELCOME->value],
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2026-05-17',
            'extracted_service' => SermonService::MORNING->value,
        ]);

        Livewire::test(ManageChurchService::class, ['churchService' => $service])
            ->set('items.0.title', 'Welcome and Notices')
            ->call('save')
            ->assertRedirect(route('admin.services.show', $service));

        Queue::assertPushed(
            ReconcileServiceSections::class,
            fn (ReconcileServiceSections $job): bool => $job->processingLogId() === $processingLog->id
                && $job->churchServiceId() === $service->id
        );

        $processingLog->refresh();
        $this->assertSame(
            'church_service_canonical_list_changed',
            $processingLog->processing_metadata['reconciliation_triggers'][0]['event'] ?? null
        );
        $this->assertSame(
            'manual',
            $processingLog->processing_metadata['reconciliation_triggers'][0]['source'] ?? null
        );
    }

    #[Test]
    public function no_op_manual_save_does_not_emit_a_canonical_list_changed_event(): void
    {
        Event::fake([ChurchServiceCanonicalListChanged::class]);
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'date' => '2026-05-24',
            'service' => SermonService::EVENING,
            'source' => 'manual',
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'custom',
            'source' => 'manual',
            'title' => 'Welcome',
            'source_title' => 'Welcome',
            'openlp_search_title' => null,
            'metadata' => ['section_type' => ServiceSectionType::WELCOME->value],
        ]);

        Livewire::test(ManageChurchService::class, ['churchService' => $service])
            ->call('save')
            ->assertRedirect(route('admin.services.show', $service));

        Event::assertNotDispatched(ChurchServiceCanonicalListChanged::class);
    }

    #[Test]
    public function manual_service_form_shows_song_autocomplete_matches_and_selects_them(): void
    {
        $this->actingAs($this->admin);

        $song = Song::factory()->create([
            'title' => 'Living Hope',
            'canonical_key' => 'living hope@',
        ]);

        Livewire::test(ManageChurchService::class)
            ->set('items.0.section_type', ServiceSectionType::SONG->value)
            ->set('items.0.title', 'Living')
            ->assertSee('Living Hope')
            ->call('selectSong', 0, $song->id)
            ->assertSet('items.0.song_id', $song->id)
            ->assertSet('items.0.title', 'Living Hope');
    }

    #[Test]
    public function manual_service_form_escapes_like_wildcards_in_song_autocomplete(): void
    {
        $this->actingAs($this->admin);

        Song::factory()->create([
            'title' => 'Living Hope',
            'canonical_key' => 'living hope@',
        ]);

        Livewire::test(ManageChurchService::class)
            ->set('items.0.section_type', ServiceSectionType::SONG->value)
            ->set('items.0.title', '%%')
            ->assertDontSee('Living Hope');
    }

    #[Test]
    public function manual_service_form_rejects_duplicate_date_and_service_pairs(): void
    {
        $this->actingAs($this->admin);

        ChurchService::factory()->create([
            'date' => '2026-05-17',
            'service' => SermonService::MORNING,
        ]);

        Livewire::test(ManageChurchService::class)
            ->set('date', '2026-05-17')
            ->set('service', SermonService::MORNING->value)
            ->set('items.0.section_type', ServiceSectionType::WELCOME->value)
            ->set('items.0.title', 'Welcome')
            ->call('save')
            ->assertHasErrors(['date' => ['unique']]);
    }

    #[Test]
    public function show_component_displays_items_and_import_warnings(): void
    {
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'date' => '2026-02-22',
            'service' => SermonService::MORNING,
            'needs_review' => true,
            'import_metadata' => [
                'confidence_score' => 0.4,
                'parse_method' => 'upload_filename',
                'filename_mismatch' => true,
                'warnings' => ['Upload filename and embedded .osj filename identities do not match.'],
            ],
        ]);

        $song = Song::factory()->create([
            'title' => 'Opening Hymn',
            'canonical_key' => 'opening hymn@',
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 2,
            'type' => 'custom',
            'title' => 'Closing Prayer',
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Opening Hymn',
            'song_id' => $song->id,
        ]);

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->assertSeeInOrder(['Opening Hymn', 'Closing Prayer'])
            ->assertSee(route('admin.services.songs.show', $song))
            ->assertSee('Upload filename and embedded .osj filename identities do not match.')
            ->assertSee('40%');
    }

    #[Test]
    public function show_component_displays_classified_sections_for_related_livestream_runs(): void
    {
        $this->actingAs($this->admin);

        $service = $this->churchServiceScenario()
            ->state([
                'date' => '2026-02-22',
                'service' => SermonService::MORNING,
            ])
            ->create();

        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Service Opening',
        ]);

        $matchingRun = $this->processingLogScenario()
            ->as(\App\Enums\MediaType::Livestream)
            ->state([
                'extracted_date' => '2026-02-22',
                'extracted_service' => SermonService::MORNING,
            ])
            ->create();

        $nonMatchingRun = $this->processingLogScenario()
            ->as(\App\Enums\MediaType::Livestream)
            ->state([
                'extracted_date' => '2026-02-23',
                'extracted_service' => SermonService::MORNING,
            ])
            ->create();

        $this->serviceSectionScenario()
            ->forProcessingLog($matchingRun)
            ->forChurchServiceItem($item)
            ->type(ServiceSectionType::SONG)
            ->needsManualReview()
            ->state([
                'section_order' => 2,
                'title' => 'Closing Song',
                'start_time' => 120.0,
                'end_time' => 360.0,
                'duration' => 240.0,
                'status' => ServiceSectionStatus::IDENTIFIED,
                'source_segment_ids' => [3],
                'metadata' => [
                    'confidence_level' => 'low',
                    'review_reason' => 'expected_type_mismatch',
                ],
                'publication_status' => ServiceSectionPublicationStatus::PENDING_APPROVAL,
            ])
            ->create();

        $this->serviceSectionScenario()
            ->forProcessingLog($matchingRun)
            ->forChurchServiceItem($item)
            ->type(ServiceSectionType::WELCOME)
            ->state([
                'section_order' => 1,
                'title' => 'Welcome',
                'start_time' => 0.0,
                'end_time' => 120.0,
                'duration' => 120.0,
                'status' => ServiceSectionStatus::IDENTIFIED,
                'source_segment_ids' => [1],
                'metadata' => [
                    'confidence_level' => 'high',
                ],
                'publication_status' => ServiceSectionPublicationStatus::PUBLISHED,
            ])
            ->create();

        $this->serviceSectionScenario()
            ->forProcessingLog($nonMatchingRun)
            ->forChurchServiceItem($item)
            ->state([
                'title' => 'Should Not Appear',
            ])
            ->create();

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('Classified Livestream Runs')
            ->assertSee($matchingRun->processing_id)
            ->assertDontSee($nonMatchingRun->processing_id)
            ->assertSeeInOrder(['Welcome', 'Closing Song'])
            ->assertSee('Needs review')
            ->assertSee('expected type mismatch')
            ->assertSee('Published')
            ->assertSee('Pending Approval');
    }

    #[Test]
    public function show_component_displays_processing_timelines_for_related_livestream_runs(): void
    {
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'date' => '2026-03-01',
            'service' => SermonService::MORNING,
        ]);

        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2026-03-01',
            'extracted_service' => SermonService::MORNING->value,
        ]);

        SermonProcessingStep::factory()->create([
            'processing_id' => $run->processing_id,
            'step' => ChurchServiceProcessingTimeline::CLASSIFY_SERVICE_SECTIONS,
            'status' => 'completed',
            'message' => 'Stored 3 classified section(s)',
            'started_at' => now()->subMinutes(30),
            'completed_at' => now()->subMinutes(29),
        ]);

        SermonProcessingStep::factory()->create([
            'processing_id' => $run->processing_id,
            'step' => ChurchServiceProcessingTimeline::TRANSCRIBE_SPEECH_SEGMENTS,
            'status' => 'failed',
            'message' => 'Transcript API timeout',
            'started_at' => now()->subMinutes(28),
            'completed_at' => now()->subMinutes(27)->subSeconds(30),
        ]);

        SermonProcessingStep::factory()->create([
            'processing_id' => $run->processing_id,
            'step' => ChurchServiceProcessingTimeline::CLASSIFY_SPEECH_SECTIONS,
            'status' => 'skipped',
            'message' => 'No sections available for transcript classification',
            'started_at' => now()->subMinutes(27),
            'completed_at' => now()->subMinutes(27),
        ]);

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('Processing Timeline')
            ->assertSeeInOrder([
                'Classify service sections',
                'Transcribe speech segments',
                'Classify speech sections',
                'Align with OoS',
                'Extract sermon',
                'Prepare publication candidates',
            ])
            ->assertSee('1m 00s')
            ->assertSee('30s')
            ->assertSee('Transcript API timeout')
            ->assertSee('Skipped')
            ->assertSee('Failed')
            ->assertSee('Not recorded')
            ->assertSee('No step log recorded for this older run.');
    }

    #[Test]
    public function reclassify_action_dispatches_classifier_for_matching_livestream_run(): void
    {
        Bus::fake();
        Storage::fake('local');
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'date' => '2026-04-05',
            'service' => SermonService::MORNING,
        ]);

        $processingRun = MediaProcessingLog::factory()->livestream()->pending()->create([
            'extracted_date' => '2026-04-05',
            'extracted_service' => SermonService::MORNING,
            'source_file_path' => 'temp/reclassify-source.mp4',
        ]);
        Storage::disk('local')->put('temp/reclassify-source.mp4', 'video');

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->call('reclassify', $processingRun->id)
            ->assertDispatched('notify', type: 'success', message: 'Section reclassification queued');

        Bus::assertDispatched(
            ClassifyServiceSections::class,
            fn (ClassifyServiceSections $job): bool => $job->preservesRunStatus()
        );
        Bus::assertChained([
            ClassifyServiceSections::class,
            TranscribeSpeechSegments::class,
            ClassifySpeechSections::class,
            ProjectLivestreamServiceStructure::class,
            AlignWithOos::class,
            ExtractSermon::class,
            SubmitToProcessing::class,
            EnhanceAudio::class,
            IdentifySpeaker::class,
            TranscribeAudio::class,
            ProcessTranscriptWithAI::class,
            GenerateThumbnail::class,
            PrepareSectionPublicationCandidates::class,
        ]);
    }

    #[Test]
    public function reclassify_action_rejects_invalid_or_non_matching_runs(): void
    {
        Bus::fake();
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'date' => '2026-04-12',
            'service' => SermonService::MORNING,
        ]);

        $videoRun = MediaProcessingLog::factory()->video()->create([
            'extracted_date' => '2026-04-12',
            'extracted_service' => SermonService::MORNING,
        ]);

        $mismatchedRun = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-04-19',
            'extracted_service' => SermonService::MORNING,
        ]);

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->call('reclassify', 999999)
            ->assertDispatched('notify', type: 'error', message: 'Processing run not found.')
            ->call('reclassify', $videoRun->id)
            ->assertDispatched('notify', type: 'error', message: 'Only livestream runs can be reclassified.')
            ->call('reclassify', $mismatchedRun->id)
            ->assertDispatched('notify', type: 'error', message: 'Selected run does not belong to this service.');

        Bus::assertNothingChained();
    }

    #[Test]
    public function reclassify_action_rejects_runs_when_the_original_source_file_is_missing(): void
    {
        Bus::fake();
        Storage::fake('local');
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'date' => '2026-04-26',
            'service' => SermonService::MORNING,
        ]);

        $processingRun = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-04-26',
            'extracted_service' => SermonService::MORNING,
            'source_file_path' => 'temp/missing-source.mp4',
        ]);

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->call('reclassify', $processingRun->id)
            ->assertDispatched('notify', type: 'error', message: 'Selected run cannot be reclassified because the original livestream file is no longer available.');

        Bus::assertNothingChained();
    }

    #[Test]
    public function show_component_displays_planned_only_list_when_no_livestream_runs_exist(): void
    {
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'date' => '2026-05-01',
            'service' => SermonService::MORNING,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Opening Prayer',
            'source' => \App\Enums\ChurchServiceItemSource::EMAIL,
        ]);

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('Order of Service')
            ->assertSee('Opening Prayer')
            ->assertSee('EMAIL')
            ->assertDontSee('Classified Livestream Runs');
    }

    #[Test]
    public function show_component_displays_unified_timeline_with_matched_sections(): void
    {
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'date' => '2026-05-08',
            'service' => SermonService::MORNING,
        ]);

        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Notices',
            'source' => \App\Enums\ChurchServiceItemSource::OPENLP,
        ]);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-08',
            'extracted_service' => SermonService::MORNING,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::NOTICES->value,
            'section_order' => 1,
            'title' => 'Notices',
            'start_time' => 300.0,
            'end_time' => 420.0,
            'metadata' => [],
        ]);

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('Classified Livestream Runs')
            ->assertSee('Notices')
            ->assertSee('OPENLP')
            ->assertSee('Aligned')
            ->assertSee('5:00')
            ->assertSee('7:00');
    }

    #[Test]
    public function show_component_shows_mismatch_row_with_expected_and_detected_values(): void
    {
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'date' => '2026-05-15',
            'service' => SermonService::MORNING,
        ]);

        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Planned Sermon',
        ]);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-15',
            'extracted_service' => SermonService::MORNING,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 1,
            'title' => 'Detected Song',
            'start_time' => 0.0,
            'end_time' => 60.0,
            'metadata' => [
                'oos_alignment' => [
                    'mismatch_reason' => 'type_mismatch',
                    'expected_item_id' => $item->id,
                    'expected_item_title' => 'Planned Sermon',
                    'expected_section_type' => ServiceSectionType::SERMON->value,
                ],
            ],
        ]);

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('Planned Sermon')
            ->assertSee('Detected Song')
            ->assertSee('Mismatch')
            ->assertSee('type mismatch');
    }

    #[Test]
    public function show_component_shows_unplanned_sections_in_timeline(): void
    {
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'date' => '2026-05-22',
            'service' => SermonService::MORNING,
        ]);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-22',
            'extracted_service' => SermonService::MORNING,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::OTHER->value,
            'section_order' => 1,
            'title' => 'Unplanned Section',
            'start_time' => 0.0,
            'end_time' => 60.0,
            'metadata' => [],
        ]);

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('Unplanned Section')
            ->assertSee('Unplanned')
            ->assertSee('Not in plan');
    }

    #[Test]
    public function show_component_shows_archived_planned_context_for_soft_deleted_items(): void
    {
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'date' => '2026-05-29',
            'service' => SermonService::MORNING,
        ]);

        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'title' => 'Deleted Item',
        ]);
        $item->delete();

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-29',
            'extracted_service' => SermonService::MORNING,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::OTHER->value,
            'section_order' => 1,
            'start_time' => 0.0,
            'end_time' => 60.0,
            'metadata' => [],
        ]);

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('Deleted Item')
            ->assertSee('Archived from plan');
    }

    #[Test]
    public function show_component_displays_pending_merge_panel_when_conflicts_exist(): void
    {
        $this->actingAs($this->admin);

        $service = $this->churchServiceScenario()
            ->livestream()
            ->state([
                'date' => '2026-03-23',
                'service' => SermonService::MORNING,
                'needs_review' => true,
                'import_metadata' => [
                    'pending_structure_merge' => [
                        'incoming_source' => 'openlp',
                        'created_at' => now()->toIso8601String(),
                        'confidence' => ['current' => 'high', 'incoming' => 'high'],
                        'conflicts' => [
                            [
                                'type' => 'title_conflict',
                                'current_item' => ['position' => 1, 'type' => 'songs', 'title' => 'Amazing Grace'],
                                'incoming_item' => ['position' => 1, 'type' => 'songs', 'title' => 'How Great Thou Art'],
                            ],
                        ],
                        'proposed_items' => [
                            ['position' => 1, 'type' => 'songs', 'title' => 'How Great Thou Art'],
                        ],
                        'classification' => ['auto_merge' => [], 'review_required' => [0], 'unmatched_incoming' => []],
                    ],
                ],
            ])
            ->create();

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('Pending Structure Merge')
            ->assertSee('Title differs')
            ->assertSee('Amazing Grace')
            ->assertSee('How Great Thou Art')
            ->assertSee('Accept incoming')
            ->assertSee('Keep current');
    }

    #[Test]
    public function show_component_does_not_display_merge_panel_when_no_pending_merge(): void
    {
        $this->actingAs($this->admin);

        $service = $this->churchServiceScenario()
            ->state([
                'date' => '2026-03-23',
                'service' => SermonService::MORNING,
                'needs_review' => false,
            ])
            ->create();

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->assertDontSee('Pending Structure Merge');
    }

    #[Test]
    public function show_component_accept_incoming_merge_resolves_and_clears_panel(): void
    {
        $this->actingAs($this->admin);
        Event::fake();

        $service = $this->churchServiceScenario()
            ->livestream()
            ->state([
                'date' => '2026-03-23',
                'service' => SermonService::MORNING,
                'needs_review' => true,
                'import_metadata' => [
                    'pending_structure_merge' => [
                        'incoming_source' => 'openlp',
                        'created_at' => now()->toIso8601String(),
                        'confidence' => ['current' => 'high', 'incoming' => 'high'],
                        'conflicts' => [
                            [
                                'type' => 'title_conflict',
                                'current_item' => ['position' => 1, 'type' => 'songs', 'title' => 'Old Song'],
                                'incoming_item' => ['position' => 1, 'type' => 'songs', 'title' => 'New Song'],
                            ],
                        ],
                        'proposed_items' => [
                            ['position' => 1, 'type' => 'songs', 'title' => 'New Song', 'source_title' => null, 'openlp_search_title' => 'new song@', 'song_id' => null, 'metadata' => null],
                        ],
                        'classification' => ['auto_merge' => [], 'review_required' => [0], 'unmatched_incoming' => []],
                    ],
                ],
            ])
            ->create();

        ChurchServiceItem::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Old Song',
            'metadata' => [
                'livestream_projection' => [
                    'processing_id' => 'test',
                    'service_section_id' => 1,
                    'source_segment_ids' => [],
                    'confidence_level' => 'high',
                ],
            ],
        ]);

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('Pending Structure Merge')
            ->call('acceptIncomingMerge')
            ->assertDispatched('notify');

        $fresh = $service->fresh();
        $this->assertFalse($fresh->needs_review);
        $metadata = $fresh->import_metadata?->toArray() ?? [];
        $this->assertArrayNotHasKey('pending_structure_merge', $metadata);
        $this->assertArrayHasKey('structure_merge_resolution', $metadata);
    }

    #[Test]
    public function show_component_keep_current_preserves_items_and_clears_panel(): void
    {
        $this->actingAs($this->admin);
        Event::fake();

        $service = $this->churchServiceScenario()
            ->livestream()
            ->state([
                'date' => '2026-03-23',
                'service' => SermonService::MORNING,
                'needs_review' => true,
                'import_metadata' => [
                    'pending_structure_merge' => [
                        'incoming_source' => 'openlp',
                        'created_at' => now()->toIso8601String(),
                        'confidence' => ['current' => 'high', 'incoming' => 'high'],
                        'conflicts' => [
                            [
                                'type' => 'title_conflict',
                                'current_item' => ['position' => 1, 'type' => 'songs', 'title' => 'Current Song'],
                                'incoming_item' => ['position' => 1, 'type' => 'songs', 'title' => 'Incoming Song'],
                            ],
                        ],
                        'proposed_items' => [
                            ['position' => 1, 'type' => 'songs', 'title' => 'Incoming Song', 'source_title' => null, 'openlp_search_title' => null, 'song_id' => null, 'metadata' => null],
                        ],
                        'classification' => ['auto_merge' => [], 'review_required' => [0], 'unmatched_incoming' => []],
                    ],
                ],
            ])
            ->create();

        ChurchServiceItem::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Current Song',
            'metadata' => [
                'livestream_projection' => [
                    'processing_id' => 'test',
                    'service_section_id' => 1,
                    'source_segment_ids' => [],
                    'confidence_level' => 'high',
                ],
            ],
        ]);

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->call('keepCurrentStructure')
            ->assertDispatched('notify');

        $fresh = $service->fresh();
        $this->assertFalse($fresh->needs_review);

        $items = $fresh->items()->orderBy('position')->get();
        $this->assertSame('Current Song', $items->first()->title);
    }

    #[Test]
    public function list_component_shows_pending_merge_badge(): void
    {
        $this->actingAs($this->admin);

        $this->churchServiceScenario()
            ->livestream()
            ->state([
                'date' => '2026-03-23',
                'service' => SermonService::MORNING,
                'needs_review' => true,
                'import_metadata' => [
                    'pending_structure_merge' => [
                        'incoming_source' => 'openlp',
                        'created_at' => now()->toIso8601String(),
                        'confidence' => ['current' => 'high', 'incoming' => 'high'],
                        'conflicts' => [],
                        'proposed_items' => [],
                        'classification' => ['auto_merge' => [], 'review_required' => [], 'unmatched_incoming' => []],
                    ],
                ],
            ])
            ->create();

        Livewire::test(ListChurchServices::class)
            ->assertSee('Pending merge');
    }

    #[Test]
    public function non_admin_cannot_access_service_admin_components(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);
        $service = ChurchService::factory()->create();

        $this->actingAs($user);

        $this->get(route('admin.services.index'))->assertForbidden();
        $this->get(route('admin.services.create'))->assertForbidden();
        $this->get(route('admin.services.upload'))->assertForbidden();
        $this->get(route('admin.services.show', $service))->assertForbidden();
    }
}
