<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\ChurchServiceItemSource;
use App\Enums\InboundEmailStatus;
use App\Enums\MediaType;
use App\Enums\SermonService;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Events\ChurchServiceCanonicalListChanged;
use App\Jobs\ReconcileServiceSections;
use App\Livewire\Admin\ChurchServices\AddToService;
use App\Livewire\Admin\ChurchServices\ListChurchServices;
use App\Livewire\Admin\ChurchServices\ManageChurchService;
use App\Livewire\Admin\ChurchServices\ShowChurchService;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\InboundEmail;
use App\Models\MediaProcessingLog;
use App\Models\SermonProcessingStep;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\User;
use App\Support\ChurchServiceProcessingTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OpenLpArchiveFactory;
use Tests\TestCase;
use Tests\Traits\BuildsTestScenarios;
use Tests\Traits\WithInboundEmailTestHelpers;

class AdminChurchServiceTest extends TestCase
{
    use BuildsTestScenarios;
    use RefreshDatabase;
    use WithInboundEmailTestHelpers;

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
                'service' => SermonService::Morning,
                'original_filename' => '2026-01-12 AM.osz',
            ])
            ->create();

        $this->churchServiceScenario()
            ->openLp()
            ->needsReview()
            ->state([
                'date' => '2026-01-19',
                'service' => SermonService::Evening,
                'original_filename' => '2026-01-19 PM.osz',
            ])
            ->create();

        Livewire::test(ListChurchServices::class)
            ->assertSee('Services')
            ->set('serviceFilter', SermonService::Evening->value)
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

        $component = Livewire::test(AddToService::class)
            ->set('file', $upload)
            ->call('importPlan');

        $service = ChurchService::query()->firstOrFail();

        $component->assertRedirect(route('admin.services.show', $service));

        $this->assertDatabaseHas('church_services', [
            'id' => $service->id,
            'date' => '2024-11-17',
            'service' => SermonService::Morning->value,
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

        Livewire::test(AddToService::class)
            ->set('file', $upload)
            ->call('importPlan');

        $this->assertDatabaseHas('church_service_items', [
            'openlp_search_title' => 'song one@',
            'song_id' => $song->id,
        ]);
    }

    #[Test]
    public function upload_component_requires_a_file(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(AddToService::class)
            ->call('importPlan')
            ->assertHasErrors(['planInput']);
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
            ->set('form.date', '2026-05-03')
            ->set('form.service', SermonService::Morning->value)
            ->set('form.items.0.section_type', ServiceSectionType::Welcome->value)
            ->set('form.items.0.title', 'Welcome and Call to Worship')
            ->call('addItem')
            ->set('form.items.1.section_type', ServiceSectionType::Song->value)
            ->set('form.items.1.title', 'Blessed')
            ->assertSee('Blessed Assurance')
            ->call('selectSong', 1, $song->id)
            ->call('addItem')
            ->set('form.items.2.section_type', ServiceSectionType::BibleReading->value)
            ->set('form.items.2.title', 'John 3:16-21')
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
        $this->assertSame(ServiceSectionType::Welcome, $service->items[0]->section_type);
        $this->assertArrayNotHasKey('section_type', $service->items[0]->metadata ?? []);
        $this->assertSame('songs', $service->items[1]->type);
        $this->assertSame($song->id, $service->items[1]->song_id);
        $this->assertSame('blessed assurance', $service->items[1]->metadata['linked_song_canonical_key'] ?? null);
        $this->assertSame('bibles', $service->items[2]->type);
        $this->assertSame(ServiceSectionType::BibleReading, $service->items[2]->section_type);
        $this->assertArrayNotHasKey('section_type', $service->items[2]->metadata ?? []);
    }

    #[Test]
    public function manual_service_items_are_managed_inside_the_form_object(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ManageChurchService::class)
            ->assertSet('form.items.0.section_type', ServiceSectionType::Song->value)
            ->call('addItem')
            ->set('form.items.1.section_type', ServiceSectionType::Welcome->value)
            ->set('form.items.1.title', 'Welcome')
            ->call('removeItem', 0)
            ->assertSet('form.items.0.section_type', ServiceSectionType::Welcome->value)
            ->assertSet('form.items.0.title', 'Welcome');

        $this->assertFalse((new \ReflectionClass(ManageChurchService::class))->hasProperty('items'));
    }

    #[Test]
    public function the_create_form_prefills_date_and_service_from_query_params(): void
    {
        $this->actingAs($this->admin);

        // Orphan inbox groups link here with their resolved date/slot so the
        // missing Sunday can be created and the workbench takes over.
        Livewire::withQueryParams(['date' => '2026-06-07', 'service' => 'morning'])
            ->test(ManageChurchService::class)
            ->assertSet('form.date', '2026-06-07')
            ->assertSet('form.service', SermonService::Morning->value);

        Livewire::withQueryParams(['date' => 'not-a-date', 'service' => 'bogus'])
            ->test(ManageChurchService::class)
            ->assertSet('form.date', '')
            ->assertSet('form.service', '');
    }

    #[Test]
    public function manual_save_emits_one_canonical_list_changed_event(): void
    {
        Event::fake([ChurchServiceCanonicalListChanged::class]);
        $this->actingAs($this->admin);

        Livewire::test(ManageChurchService::class)
            ->set('form.date', '2026-05-03')
            ->set('form.service', SermonService::Morning->value)
            ->set('form.items.0.section_type', ServiceSectionType::Welcome->value)
            ->set('form.items.0.title', 'Welcome and Call to Worship')
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
            'service' => SermonService::Evening,
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
            'metadata' => ['section_type' => ServiceSectionType::Welcome->value],
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 2,
            'type' => 'custom',
            'title' => 'Opening Prayer',
            'metadata' => ['section_type' => ServiceSectionType::Prayer->value],
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 3,
            'type' => 'custom',
            'title' => 'Sermon',
            'metadata' => ['section_type' => ServiceSectionType::Sermon->value],
        ]);

        $song = Song::factory()->create([
            'title' => 'Closing Song',
            'canonical_key' => 'closing song',
        ]);

        $component = Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->call('startEditingOrderOfService')
            ->assertSet('form.items.0.section_type', ServiceSectionType::Welcome->value)
            ->assertSet('form.items.1.section_type', ServiceSectionType::Prayer->value)
            ->call('moveItemDown', 0)
            ->call('removeItem', 2)
            ->set('form.items.1.title', 'Welcome and Notices')
            ->call('addItem')
            ->set('form.items.2.section_type', ServiceSectionType::Song->value)
            ->set('form.items.2.title', 'Closing')
            ->assertSee('Closing Song')
            ->call('selectSong', 2, $song->id)
            ->call('save');

        $service->refresh();
        $service->load(['items' => fn ($query) => $query->orderBy('position')->orderBy('id')]);

        $component->assertNoRedirect();

        $this->assertSame('manual', $service->source);
        $this->assertFalse($service->needs_review);
        $this->assertSame('2026-05-10 PM.osz', $service->original_filename);
        $this->assertSame(3, $service->items->count());
        $this->assertSame('Opening Prayer', $service->items[0]->title);
        $this->assertSame(ServiceSectionType::Prayer, $service->items[0]->section_type);
        $this->assertArrayNotHasKey('section_type', $service->items[0]->metadata ?? []);
        $this->assertSame('Welcome and Notices', $service->items[1]->title);
        $this->assertSame(ServiceSectionType::Welcome, $service->items[1]->section_type);
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
            'service' => SermonService::Morning,
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
            'metadata' => ['section_type' => ServiceSectionType::Welcome->value],
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2026-05-17',
            'extracted_service' => SermonService::Morning->value,
        ]);

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->call('startEditingOrderOfService')
            ->set('form.items.0.title', 'Welcome and Notices')
            ->call('save')
            ->assertNoRedirect();

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
            'service' => SermonService::Evening,
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
            'metadata' => ['section_type' => ServiceSectionType::Welcome->value],
        ]);

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->call('startEditingOrderOfService')
            ->call('save')
            ->assertNoRedirect();

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
            ->set('form.items.0.section_type', ServiceSectionType::Song->value)
            ->set('form.items.0.title', 'Living')
            ->assertSee('Living Hope')
            ->call('selectSong', 0, $song->id)
            ->assertSet('form.items.0.song_id', $song->id)
            ->assertSet('form.items.0.title', 'Living Hope');
    }

    #[Test]
    public function manual_service_form_suggests_songs_a_substring_search_cannot_reach(): void
    {
        $this->actingAs($this->admin);

        $numbered = Song::factory()->create([
            'title' => 'Sing to God',
            'canonical_key' => 'sing to god',
            'praise_number' => '98',
        ]);

        Livewire::test(ManageChurchService::class)
            ->set('form.items.0.section_type', ServiceSectionType::Song->value)
            ->set('form.items.0.title', '98 Sing to God')
            ->assertSee('Sing to God')
            ->call('selectSong', 0, $numbered->id)
            ->assertSet('form.items.0.song_id', $numbered->id);
    }

    #[Test]
    public function manual_service_form_leads_the_suggestions_with_the_resolved_song(): void
    {
        $this->actingAs($this->admin);

        $resolved = Song::factory()->create([
            'title' => 'Restore O Lord',
            'canonical_key' => 'restore o lord',
        ]);

        // Sorts before the resolved title alphabetically, so it would head a plain LIKE list.
        Song::factory()->create([
            'title' => 'Before you restore O Lord we wait',
            'canonical_key' => 'before you restore o lord we wait',
        ]);

        $suggestions = Livewire::test(ManageChurchService::class)
            ->set('form.items.0.section_type', ServiceSectionType::Song->value)
            ->set('form.items.0.title', 'NIP ‘Restore O Lord’')
            ->viewData('songSuggestions');

        $this->assertSame($resolved->id, $suggestions[0][0]['id']);
    }

    #[Test]
    public function confirming_a_song_titles_the_item_from_the_catalogue_and_keeps_the_typed_line(): void
    {
        $this->actingAs($this->admin);

        $song = Song::factory()->create([
            'title' => 'Living Hope',
            'canonical_key' => 'living hope',
        ]);

        Livewire::test(ManageChurchService::class)
            ->set('form.items.0.section_type', ServiceSectionType::Song->value)
            ->set('form.items.0.title', 'NIP ‘Living Hope’')
            ->call('selectSong', 0, $song->id)
            ->assertSet('form.items.0.title', 'Living Hope')
            // The line that was replaced becomes the item's provenance rather than being lost.
            ->assertSet('form.items.0.source_title', 'NIP ‘Living Hope’')
            ->assertSee('From the order of service')
            // The title field now names the song, so the panel confirms the link
            // instead of repeating the title back.
            ->assertSee('Linked to the song catalogue.')
            ->assertDontSee('Linked song: Living Hope');
    }

    #[Test]
    public function editing_an_existing_service_names_a_linked_song_whose_title_differs(): void
    {
        $this->actingAs($this->admin);

        $song = Song::factory()->create([
            'title' => 'Holy Spirit, living breath of God',
            'canonical_key' => 'holy spirit living breath of god',
        ]);

        $service = ChurchService::factory()->create([
            'date' => '2026-07-12',
            'service' => SermonService::Morning,
        ]);

        ChurchServiceItem::factory()->song()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'source' => ChurchServiceItemSource::Email->value,
            'title' => 'NIP ‘Holy, Spirit, living breath of God’',
            'source_title' => 'NIP ‘Holy, Spirit, living breath of God’',
            'openlp_search_title' => null,
            'song_id' => $song->id,
        ]);

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->call('startEditingOrderOfService')
            ->assertSee('Linked song: Holy Spirit, living breath of God')
            // This row predates title cleaning, so its title and its raw line are the same
            // string — there is nothing for a provenance line to add.
            ->assertDontSee('From the order of service');
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
            ->set('form.items.0.section_type', ServiceSectionType::Song->value)
            ->set('form.items.0.title', '%%')
            ->assertDontSee('Living Hope');
    }

    #[Test]
    public function manual_service_form_rejects_duplicate_date_and_service_pairs(): void
    {
        $this->actingAs($this->admin);

        ChurchService::factory()->create([
            'date' => '2026-05-17',
            'service' => SermonService::Morning,
        ]);

        Livewire::test(ManageChurchService::class)
            ->set('form.date', '2026-05-17')
            ->set('form.service', SermonService::Morning->value)
            ->set('form.items.0.section_type', ServiceSectionType::Welcome->value)
            ->set('form.items.0.title', 'Welcome')
            ->call('save')
            ->assertHasErrors(['form.date' => ['unique']]);
    }

    #[Test]
    public function show_component_displays_items_and_import_warnings(): void
    {
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'date' => '2026-02-22',
            'service' => SermonService::Morning,
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
                'service' => SermonService::Morning,
            ])
            ->create();

        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Service Opening',
        ]);

        $matchingRun = $this->processingLogScenario()
            ->as(MediaType::Livestream)
            ->state([
                'extracted_date' => '2026-02-22',
                'extracted_service' => SermonService::Morning,
            ])
            ->create();

        $nonMatchingRun = $this->processingLogScenario()
            ->as(MediaType::Livestream)
            ->state([
                'extracted_date' => '2026-02-23',
                'extracted_service' => SermonService::Morning,
            ])
            ->create();

        $this->serviceSectionScenario()
            ->forProcessingLog($matchingRun)
            ->forChurchServiceItem($item)
            ->type(ServiceSectionType::Song)
            ->needsManualReview()
            ->state([
                'section_order' => 2,
                'title' => 'Closing Song',
                'start_time' => 120.0,
                'end_time' => 360.0,
                'duration' => 240.0,
                'status' => ServiceSectionStatus::Identified,
                'source_segment_ids' => [3],
                'metadata' => [
                    'confidence_level' => 'low',
                    'review_reason' => 'expected_type_mismatch',
                ],
                'publication_status' => ServiceSectionPublicationStatus::PendingApproval,
            ])
            ->create();

        $this->serviceSectionScenario()
            ->forProcessingLog($matchingRun)
            ->forChurchServiceItem($item)
            ->type(ServiceSectionType::Welcome)
            ->state([
                'section_order' => 1,
                'title' => 'Welcome',
                'start_time' => 0.0,
                'end_time' => 120.0,
                'duration' => 120.0,
                'status' => ServiceSectionStatus::Identified,
                'source_segment_ids' => [1],
                'metadata' => [
                    'confidence_level' => 'high',
                ],
                'publication_status' => ServiceSectionPublicationStatus::Published,
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
            ->assertSee('Service record')
            ->assertSee($matchingRun->processing_id)
            ->assertDontSee($nonMatchingRun->processing_id)
            ->assertSeeInOrder(['Welcome', 'Closing Song'])
            ->assertDontSee('expected type mismatch')
            ->assertSee('Published')
            ->assertSee('Pending Approval');
    }

    #[Test]
    public function show_component_displays_processing_timelines_for_related_livestream_runs(): void
    {
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'date' => '2026-03-01',
            'service' => SermonService::Morning,
        ]);

        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2026-03-01',
            'extracted_service' => SermonService::Morning->value,
        ]);

        SermonProcessingStep::factory()->create([
            'processing_id' => $run->processing_id,
            'step' => ChurchServiceProcessingTimeline::TRANSCRIBE_FULL_SERVICE,
            'status' => 'completed',
            'message' => 'Stored full-service transcript',
            'started_at' => now()->subMinutes(30),
            'completed_at' => now()->subMinutes(29),
        ]);

        SermonProcessingStep::factory()->create([
            'processing_id' => $run->processing_id,
            'step' => ChurchServiceProcessingTimeline::DETECT_SERVICE_STRUCTURE,
            'status' => 'failed',
            'message' => 'Transcript API timeout',
            'started_at' => now()->subMinutes(28),
            'completed_at' => now()->subMinutes(27)->subSeconds(30),
        ]);

        SermonProcessingStep::factory()->create([
            'processing_id' => $run->processing_id,
            'step' => ChurchServiceProcessingTimeline::PROJECT_LIVESTREAM_SERVICE_STRUCTURE,
            'status' => 'skipped',
            'message' => 'No sections available for transcript classification',
            'started_at' => now()->subMinutes(27),
            'completed_at' => now()->subMinutes(27),
        ]);

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('Technical processing details')
            ->assertSee('Recorded processing steps')
            ->assertSeeInOrder([
                'Transcribe full service',
                'Detect service structure',
                'Project service structure',
            ])
            ->assertSee('1m 00s')
            ->assertSee('30s')
            ->assertSee('Transcript API timeout')
            ->assertSee('Skipped')
            ->assertSee('Failed')
            ->assertDontSee('No step log recorded for this older run.');
    }

    #[Test]
    public function show_component_displays_planned_only_list_when_no_livestream_runs_exist(): void
    {
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'date' => '2026-05-01',
            'service' => SermonService::Morning,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Opening Prayer',
            'source' => ChurchServiceItemSource::Email,
        ]);

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('Service record')
            ->assertSee('Plan imported from an email')
            ->assertDontSee('Other uploads');
    }

    #[Test]
    public function show_component_displays_unified_timeline_with_matched_sections(): void
    {
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'date' => '2026-05-08',
            'service' => SermonService::Morning,
        ]);

        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Notices',
            'source' => ChurchServiceItemSource::OpenLp,
        ]);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-08',
            'extracted_service' => SermonService::Morning,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Notices->value,
            'section_order' => 1,
            'title' => 'Notices',
            'start_time' => 300.0,
            'end_time' => 420.0,
            'metadata' => [],
        ]);

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('Service record')
            ->assertSee('Notices')
            ->assertSee('Matches plan')
            ->assertSee('5:00')
            ->assertSee('7:00');
    }

    #[Test]
    public function show_component_shows_mismatch_row_with_expected_and_detected_values(): void
    {
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'date' => '2026-05-15',
            'service' => SermonService::Morning,
        ]);

        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Planned Sermon',
        ]);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-15',
            'extracted_service' => SermonService::Morning,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'title' => 'Detected Song',
            'start_time' => 0.0,
            'end_time' => 60.0,
            'metadata' => [
                'oos_alignment' => [
                    'mismatch_reason' => 'type_mismatch',
                    'expected_item_id' => $item->id,
                    'expected_item_title' => 'Planned Sermon',
                    'expected_section_type' => ServiceSectionType::Sermon->value,
                ],
            ],
        ]);

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('Planned Sermon')
            ->assertSee('Detected Song')
            ->assertSee('Mismatch')
            ->assertSee('Type mismatch');
    }

    #[Test]
    public function show_component_shows_unplanned_sections_in_timeline(): void
    {
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'date' => '2026-05-22',
            'service' => SermonService::Morning,
        ]);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-22',
            'extracted_service' => SermonService::Morning,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 1,
            'title' => 'Unplanned Section',
            'start_time' => 0.0,
            'end_time' => 60.0,
            'metadata' => [],
        ]);

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('Unplanned Section')
            ->assertSee('Recording only')
            ->assertDontSee('Not in plan');
    }

    #[Test]
    public function show_component_shows_archived_planned_context_for_soft_deleted_items(): void
    {
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'date' => '2026-05-29',
            'service' => SermonService::Morning,
        ]);

        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'title' => 'Deleted Item',
        ]);
        $item->delete();

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-29',
            'extracted_service' => SermonService::Morning,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 1,
            'start_time' => 0.0,
            'end_time' => 60.0,
            'metadata' => [],
        ]);

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('Deleted Item')
            ->assertSee('Matches plan');
    }

    #[Test]
    public function show_component_displays_pending_merge_panel_when_conflicts_exist(): void
    {
        $this->actingAs($this->admin);

        $service = $this->churchServiceScenario()
            ->livestream()
            ->state([
                'date' => '2026-03-23',
                'service' => SermonService::Morning,
                'needs_review' => true,
                'pending_structure_merge_source' => ChurchServiceItemSource::OpenLp->value,
                'import_metadata' => [
                    'pending_structure_merge' => [
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
                'service' => SermonService::Morning,
                'needs_review' => false,
            ])
            ->create();

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->assertDontSee('Pending Structure Merge');
    }

    #[Test]
    public function it_uses_the_admin_list_shell_with_loading_states(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListChurchServices::class)
            ->assertSeeHtml('wire:loading.class.delay.200ms="opacity-50"');
    }

    #[Test]
    public function manage_service_form_has_removal_confirmation(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ManageChurchService::class)
            ->assertSeeHtml('wire:confirm="Remove this service item?"')
            ->call('addItem')
            ->assertCount('form.items', 2)
            ->call('removeItem', 1)
            ->assertCount('form.items', 1);
    }

    #[Test]
    public function list_component_rolls_pending_merge_into_the_needs_review_chip(): void
    {
        $this->actingAs($this->admin);

        $this->churchServiceScenario()
            ->livestream()
            ->state([
                'date' => '2026-03-23',
                'service' => SermonService::Morning,
                'needs_review' => true,
                'pending_structure_merge_source' => 'UPLOAD',
                'import_metadata' => [
                    'pending_structure_merge' => [
                        'created_at' => now()->toIso8601String(),
                        'confidence' => ['current' => 'high', 'incoming' => 'high'],
                        'conflicts' => [],
                        'proposed_items' => [],
                        'classification' => ['auto_merge' => [], 'review_required' => [], 'unmatched_incoming' => []],
                    ],
                ],
            ])
            ->create();

        // needs_review flag + pending merge = 2 attention items rolled up
        Livewire::test(ListChurchServices::class)
            ->assertSee('Needs review (2)')
            ->assertSee('1 plan conflict to resolve · 1 service needs checking');
    }

    #[Test]
    public function hub_shows_rollup_status_chips_for_services_without_runs(): void
    {
        $this->actingAs($this->admin);

        ChurchService::factory()->create([
            'date' => now()->subDays(10)->toDateString(),
            'service' => SermonService::Morning,
            'needs_review' => false,
        ]);

        ChurchService::factory()->create([
            'date' => now()->addDays(10)->toDateString(),
            'service' => SermonService::Evening,
            'needs_review' => false,
        ]);

        Livewire::test(ListChurchServices::class)
            ->assertSee('Awaiting recording')
            ->assertSee('Plan only');
    }

    #[Test]
    public function hub_shows_an_all_caught_up_attention_strip_when_nothing_is_pending(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListChurchServices::class)
            ->assertSee('All caught up');
    }

    #[Test]
    public function hub_folds_attention_items_into_service_groups(): void
    {
        $this->actingAs($this->admin);

        InboundEmail::factory()->create();

        $this->churchServiceScenario()
            ->needsReview()
            ->state(['date' => '2026-01-19', 'service' => SermonService::Evening])
            ->create();

        Livewire::test(ListChurchServices::class)
            ->assertSee('Needs attention')
            ->assertSee('service needs checking')
            ->assertSeeHtml(route('admin.services.show', ChurchService::query()->whereDate('date', '2026-01-19')->firstOrFail()))
            ->assertDontSee('All caught up');
    }

    #[Test]
    public function hub_header_offers_a_single_add_button(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListChurchServices::class)
            ->assertSee('Add')
            ->assertSee('Song catalogue')
            ->assertSeeHtml(route('admin.services.add'));
    }

    #[Test]
    public function hub_hero_picks_the_service_closest_to_today(): void
    {
        $this->actingAs($this->admin);

        $near = ChurchService::factory()->create([
            'date' => now()->addDays(2)->toDateString(),
            'service' => SermonService::Morning,
            'needs_review' => false,
        ]);

        ChurchService::factory()->create([
            'date' => now()->addDays(6)->toDateString(),
            'service' => SermonService::Evening,
            'needs_review' => false,
        ]);

        Livewire::test(ListChurchServices::class)
            ->assertSee('This Sunday')
            ->assertSee($near->date->format('j M Y').' — Morning')
            ->assertSee('Open service');
    }

    #[Test]
    public function hub_hero_tie_breaks_equidistant_services_on_attention(): void
    {
        $this->actingAs($this->admin);

        $flaggedPast = ChurchService::factory()->create([
            'date' => now()->subDays(3)->toDateString(),
            'service' => SermonService::Morning,
            'needs_review' => true,
        ]);

        ChurchService::factory()->create([
            'date' => now()->addDays(3)->toDateString(),
            'service' => SermonService::Morning,
            'needs_review' => false,
        ]);

        Livewire::test(ListChurchServices::class)
            ->assertSee($flaggedPast->date->format('j M Y').' — Morning');
    }

    #[Test]
    public function hub_hero_falls_back_to_the_most_recent_past_service(): void
    {
        $this->actingAs($this->admin);

        $old = ChurchService::factory()->create([
            'date' => now()->subDays(30)->toDateString(),
            'service' => SermonService::Morning,
            'needs_review' => false,
        ]);

        ChurchService::factory()->create([
            'date' => now()->subDays(60)->toDateString(),
            'service' => SermonService::Morning,
            'needs_review' => false,
        ]);

        Livewire::test(ListChurchServices::class)
            ->assertSee('Most recent service')
            ->assertSee($old->date->format('j M Y').' — Morning');
    }

    #[Test]
    public function hub_returns_404_when_service_tracking_is_disabled(): void
    {
        config(['service-tracking.enabled' => false]);

        $this->actingAs($this->admin)
            ->get(route('admin.services.index'))
            ->assertNotFound();
    }

    #[Test]
    public function show_component_renders_the_pipeline_stepper(): void
    {
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'date' => '2026-05-08',
            'service' => SermonService::Morning,
            'needs_review' => false,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Welcome',
        ]);

        MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2026-05-08',
            'extracted_service' => SermonService::Morning,
            'sermon_id' => null,
        ]);

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('Ready')
            ->assertDontSeeHtml('aria-label="Pipeline progress"');
    }

    #[Test]
    public function show_component_stepper_marks_review_blocked_when_a_section_is_flagged(): void
    {
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'date' => '2026-05-08',
            'service' => SermonService::Morning,
            'needs_review' => false,
        ]);

        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2026-05-08',
            'extracted_service' => SermonService::Morning,
            'sermon_id' => null,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'needs_manual_review' => true,
        ]);

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('Needs review');
    }

    #[Test]
    public function hub_service_type_chips_are_neutral(): void
    {
        $this->actingAs($this->admin);

        $this->churchServiceScenario()
            ->state(['date' => '2026-01-12', 'service' => SermonService::Morning])
            ->create();

        Livewire::test(ListChurchServices::class)
            ->assertSeeHtml('bg-gray-100 text-gray-700')
            ->assertDontSeeHtml('bg-green-100 text-green-800');
    }

    // Migrated from AdminInboundEmailReviewTest (P5) — the standalone email
    // review page is retired; this pins the ManageChurchService prefill path
    // used by the inbox's "Edit & approve" action.
    #[Test]
    public function manual_service_form_prefills_from_an_inbound_email_and_marks_it_processed_on_save(): void
    {
        $this->actingAs($this->admin);

        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata(
                resolvedDate: '2026-07-06',
                resolvedService: SermonService::Morning->value,
                items: [
                    ['type' => 'custom', 'title' => 'Welcome', 'metadata' => ['email_type' => 'welcome']],
                    ['type' => 'custom', 'title' => 'Opening Prayer', 'metadata' => ['email_type' => 'prayer']],
                ],
            ),
        ]);

        $component = Livewire::test(ManageChurchService::class, ['inboundEmailId' => $email->id])
            ->assertSet('form.date', '2026-07-06')
            ->assertSet('form.service', SermonService::Morning->value)
            ->assertSet('form.items.0.title', 'Welcome')
            ->assertSet('form.items.1.title', 'Opening Prayer')
            ->call('save');

        $service = ChurchService::query()
            ->where('date', '2026-07-06')
            ->where('service', SermonService::Morning->value)
            ->sole();

        $component->assertRedirect(route('admin.services.show', $service));

        $this->assertSame('manual', $service->source);

        $email->refresh();
        $this->assertSame(InboundEmailStatus::Processed, $email->status);
        $this->assertSame('manual_edit', $email->processing_metadata['review']['mode'] ?? null);
        $this->assertSame($service->id, $email->processing_metadata['imported_church_service_id'] ?? null);
    }

    #[Test]
    public function an_inferred_prefilled_song_link_is_not_saved_as_a_confirmed_link(): void
    {
        $this->actingAs($this->admin);

        $song = Song::factory()->create([
            'title' => 'How Deep the Fathers Love For Us',
            'canonical_key' => 'how deep the fathers love for us',
        ]);
        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata(
                resolvedDate: '2026-07-12',
                resolvedService: SermonService::Morning->value,
                items: [
                    ['type' => 'songs', 'title' => 'How Deep the Fathers Love', 'source_title' => 'How Deep the Fathers Love'],
                ],
            ),
        ]);

        Livewire::test(ManageChurchService::class, ['inboundEmailId' => $email->id])
            ->assertSet('form.items.0.song_id', $song->id)
            ->assertSet('form.items.0.inferred_song_link', true)
            ->assertSee('Suggested from the catalogue — confirm.')
            ->call('save');

        $item = ChurchServiceItem::query()
            ->whereRelation('churchService', 'date', '2026-07-12')
            ->sole();

        $this->assertSame($song->id, $item->song_id);
        $this->assertArrayNotHasKey('linked_song_canonical_key', $item->metadata ?? []);
    }

    #[Test]
    public function editing_or_confirming_an_inferred_song_link_clears_the_suggestion(): void
    {
        $this->actingAs($this->admin);

        $song = Song::factory()->create([
            'title' => 'How Deep the Fathers Love For Us',
            'canonical_key' => 'how deep the fathers love for us',
        ]);
        $email = InboundEmail::factory()->create([
            'processing_metadata' => $this->processingMetadata(
                resolvedDate: '2026-07-19',
                resolvedService: SermonService::Morning->value,
                items: [
                    ['type' => 'songs', 'title' => 'How Deep the Fathers Love', 'source_title' => 'How Deep the Fathers Love'],
                ],
            ),
        ]);

        Livewire::test(ManageChurchService::class, ['inboundEmailId' => $email->id])
            ->call('selectSong', 0, $song->id)
            ->assertSet('form.items.0.inferred_song_link', false);

        Livewire::test(ManageChurchService::class, ['inboundEmailId' => $email->id])
            ->set('form.items.0.title', 'A corrected song title')
            ->assertSet('form.items.0.inferred_song_link', false)
            ->assertSet('form.items.0.song_id', null);
    }

    #[Test]
    public function an_existing_audited_song_link_is_loaded_as_inferred_without_an_explicit_marker(): void
    {
        $this->actingAs($this->admin);

        $song = Song::factory()->create();
        $service = ChurchService::factory()->create([
            'date' => '2026-07-26',
            'service' => SermonService::Morning,
        ]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'songs',
            'section_type' => ServiceSectionType::Song,
            'title' => $song->title,
            'song_id' => $song->id,
            'metadata' => ['song_link' => ['match_type' => 'fuzzy', 'confidence' => 0.9]],
        ]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 2,
            'type' => 'songs',
            'section_type' => ServiceSectionType::Song,
            'title' => $song->title,
            'song_id' => $song->id,
            'metadata' => [
                'song_link' => ['match_type' => 'fuzzy', 'confidence' => 0.9],
                'linked_song_canonical_key' => $song->canonical_key,
            ],
        ]);

        Livewire::test(ShowChurchService::class, ['churchService' => $service])
            ->assertSet('form.items.0.inferred_song_link', true)
            ->assertSet('form.items.1.inferred_song_link', false);
    }

    #[Test]
    public function saving_a_reviewed_plan_keeps_the_email_line_behind_an_edited_title(): void
    {
        $this->actingAs($this->admin);

        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata(
                resolvedDate: '2026-07-12',
                resolvedService: SermonService::Morning->value,
                items: [
                    [
                        'type' => 'custom',
                        'title' => 'Notices',
                        'source_title' => 'Notices (see above)',
                        'metadata' => ['email_type' => 'notices'],
                    ],
                ],
            ),
        ]);

        Livewire::test(ManageChurchService::class, ['inboundEmailId' => $email->id])
            ->assertSet('form.items.0.title', 'Notices')
            ->assertSet('form.items.0.source_title', 'Notices (see above)')
            ->set('form.items.0.title', 'Notices and welcome')
            ->call('save');

        $item = ChurchServiceItem::query()
            ->whereRelation('churchService', 'date', '2026-07-12')
            ->sole();

        $this->assertSame('Notices and welcome', $item->title);
        // The raw line is provenance, not a copy of the title: retitling an item must not
        // erase the text ChurchServiceItemSyncService matches an OpenLP export against.
        $this->assertSame('Notices (see above)', $item->source_title);
    }

    #[Test]
    public function saving_a_reading_records_the_passage_named_on_screen(): void
    {
        $this->actingAs($this->admin);

        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata(
                resolvedDate: '2026-07-12',
                resolvedService: SermonService::Morning->value,
                items: [
                    [
                        'type' => 'bibles',
                        'title' => 'Bible Reading: Joshua 5:13-6:27',
                        'source_title' => 'Bible Reading: Joshua 5:13-6:27',
                        'metadata' => null,
                    ],
                ],
            ),
        ]);

        Livewire::test(ManageChurchService::class, ['inboundEmailId' => $email->id])
            ->assertSet('form.items.0.title', 'Joshua 5:13-6:27')
            // The reviewer corrects the passage, so the recorded reference must follow the
            // screen rather than the parse it was prefilled from.
            ->set('form.items.0.title', 'Joshua 6:1-20')
            ->call('save');

        $item = ChurchServiceItem::query()
            ->whereRelation('churchService', 'date', '2026-07-12')
            ->sole();

        $this->assertSame('Joshua 6:1-20', $item->title);
        $this->assertSame('Joshua 6:1-20', $item->metadata['reading_reference'] ?? null);
    }

    #[Test]
    public function a_hand_added_item_stands_as_its_own_provenance(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ManageChurchService::class)
            ->set('form.date', '2026-07-19')
            ->set('form.service', SermonService::Morning->value)
            ->set('form.items.0.section_type', ServiceSectionType::Prayer->value)
            ->set('form.items.0.title', 'Closing prayer')
            ->call('save');

        $item = ChurchServiceItem::query()
            ->whereRelation('churchService', 'date', '2026-07-19')
            ->sole();

        // There is no external line to hold, so the title is the best provenance available —
        // which is what source_title held for every manual save before it was split out.
        $this->assertSame('Closing prayer', $item->title);
        $this->assertSame('Closing prayer', $item->source_title);
    }
}
