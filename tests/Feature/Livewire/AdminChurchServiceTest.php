<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\SermonService;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\AlignWithOos;
use App\Jobs\ClassifyServiceSections;
use App\Jobs\ClassifySpeechSections;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Jobs\TranscribeSpeechSegments;
use App\Livewire\Admin\ChurchServices\ListChurchServices;
use App\Livewire\Admin\ChurchServices\ManageChurchService;
use App\Livewire\Admin\ChurchServices\ShowChurchService;
use App\Livewire\Admin\ChurchServices\UploadChurchService;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OpenLpArchiveFactory;
use Tests\TestCase;

class AdminChurchServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function admin_can_view_and_filter_services_in_list(): void
    {
        $this->actingAs($this->admin);

        ChurchService::factory()->create([
            'date' => '2026-01-12',
            'service' => SermonService::MORNING,
            'original_filename' => '2026-01-12 AM.osz',
            'needs_review' => false,
        ]);

        ChurchService::factory()->create([
            'date' => '2026-01-19',
            'service' => SermonService::EVENING,
            'original_filename' => '2026-01-19 PM.osz',
            'needs_review' => true,
        ]);

        Livewire::test(ListChurchServices::class)
            ->assertSee('Services')
            ->set('serviceFilter', SermonService::EVENING->value)
            ->assertSee('2026-01-19 PM.osz')
            ->assertDontSee('2026-01-12 AM.osz')
            ->set('serviceFilter', null)
            ->set('needsReviewFilter', '1')
            ->assertSee('2026-01-19 PM.osz')
            ->assertDontSee('2026-01-12 AM.osz');
    }

    #[Test]
    public function list_resets_invalid_sort_input_to_safe_defaults(): void
    {
        $this->actingAs($this->admin);

        ChurchService::factory()->create([
            'date' => '2026-02-01',
            'original_filename' => '2026-02-01 AM.osz',
        ]);

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

        $archiveContents = file_get_contents($openLpUpload->getRealPath());
        if ($archiveContents === false) {
            self::fail('Failed to read generated OpenLP archive.');
        }

        $upload = UploadedFile::fake()
            ->createWithContent('2024-11-17 AM.osz', $archiveContents)
            ->mimeType('application/zip');

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
            'canonical_key' => 'song one@',
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

        $archiveContents = file_get_contents($openLpUpload->getRealPath());
        if ($archiveContents === false) {
            self::fail('Failed to read generated OpenLP archive.');
        }

        $upload = UploadedFile::fake()
            ->createWithContent('2024-11-17 AM.osz', $archiveContents)
            ->mimeType('application/zip');

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
            'canonical_key' => 'blessed assurance@',
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
        $this->assertSame(ServiceSectionType::WELCOME->value, $service->items[0]->metadata['section_type'] ?? null);
        $this->assertSame('songs', $service->items[1]->type);
        $this->assertSame($song->id, $service->items[1]->song_id);
        $this->assertSame('blessed assurance@', $service->items[1]->metadata['linked_song_canonical_key'] ?? null);
        $this->assertSame('bibles', $service->items[2]->type);
        $this->assertSame(ServiceSectionType::BIBLE_READING->value, $service->items[2]->metadata['section_type'] ?? null);
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
            'title' => 'Welcome',
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
            'canonical_key' => 'closing song@',
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
        $this->assertSame(ServiceSectionType::PRAYER->value, $service->items[0]->metadata['section_type'] ?? null);
        $this->assertSame('Welcome and Notices', $service->items[1]->title);
        $this->assertSame(ServiceSectionType::WELCOME->value, $service->items[1]->metadata['section_type'] ?? null);
        $this->assertSame('Closing Song', $service->items[2]->title);
        $this->assertSame($song->id, $service->items[2]->song_id);
        $this->assertDatabaseMissing('church_service_items', [
            'church_service_id' => $service->id,
            'title' => 'Sermon',
            'deleted_at' => null,
        ]);
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

        $service = ChurchService::factory()->create([
            'date' => '2026-02-22',
            'service' => SermonService::MORNING,
        ]);

        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Service Opening',
        ]);

        $matchingRun = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-02-22',
            'extracted_service' => SermonService::MORNING,
        ]);

        $nonMatchingRun = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-02-23',
            'extracted_service' => SermonService::MORNING,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $matchingRun->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 2,
            'title' => 'Closing Song',
            'start_time' => 120.0,
            'end_time' => 360.0,
            'duration' => 240.0,
            'status' => ServiceSectionStatus::IDENTIFIED->value,
            'needs_manual_review' => true,
            'source_segment_ids' => [3],
            'metadata' => [
                'confidence_level' => 'low',
                'review_reason' => 'expected_type_mismatch',
            ],
            'publication_status' => ServiceSectionPublicationStatus::PENDING_APPROVAL->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $matchingRun->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::WELCOME->value,
            'section_order' => 1,
            'title' => 'Welcome',
            'start_time' => 0.0,
            'end_time' => 120.0,
            'duration' => 120.0,
            'status' => ServiceSectionStatus::IDENTIFIED->value,
            'needs_manual_review' => false,
            'source_segment_ids' => [1],
            'metadata' => [
                'confidence_level' => 'high',
            ],
            'publication_status' => ServiceSectionPublicationStatus::PUBLISHED->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $nonMatchingRun->id,
            'church_service_item_id' => $item->id,
            'title' => 'Should Not Appear',
        ]);

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('Classified Livestream Runs')
            ->assertSee($matchingRun->processing_id)
            ->assertDontSee($nonMatchingRun->processing_id)
            ->assertSeeInOrder(['Welcome', 'Closing Song'])
            ->assertSee('High')
            ->assertSee('Low')
            ->assertSee('Needs review')
            ->assertSee('expected type mismatch')
            ->assertSee('Published')
            ->assertSee('Pending Approval');
    }

    #[Test]
    public function reclassify_action_dispatches_classifier_for_matching_livestream_run(): void
    {
        Bus::fake();
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'date' => '2026-04-05',
            'service' => SermonService::MORNING,
        ]);

        $processingRun = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-04-05',
            'extracted_service' => SermonService::MORNING,
        ]);

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
            AlignWithOos::class,
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
    public function non_admin_cannot_access_service_admin_components(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);
        $service = ChurchService::factory()->create();

        $this->actingAs($user);

        Livewire::test(ListChurchServices::class)->assertForbidden();
        Livewire::test(ManageChurchService::class)->assertForbidden();
        Livewire::test(UploadChurchService::class)->assertForbidden();
        Livewire::test(ShowChurchService::class, ['churchService' => $service])->assertForbidden();
    }
}
