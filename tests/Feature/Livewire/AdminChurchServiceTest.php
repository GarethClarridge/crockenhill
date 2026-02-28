<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\SermonService;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\ClassifyServiceSections;
use App\Livewire\Admin\ChurchServices\ListChurchServices;
use App\Livewire\Admin\ChurchServices\ShowChurchService;
use App\Livewire\Admin\ChurchServices\UploadChurchService;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
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
    public function upload_component_requires_a_file(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(UploadChurchService::class)
            ->call('save')
            ->assertHasErrors(['file' => ['required']]);
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
            ->assertSee('expected type mismatch');
    }

    #[Test]
    public function reclassify_action_dispatches_classifier_for_matching_livestream_run(): void
    {
        Queue::fake();
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

        Queue::assertPushed(ClassifyServiceSections::class, 1);
    }

    #[Test]
    public function reclassify_action_rejects_invalid_or_non_matching_runs(): void
    {
        Queue::fake();
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

        Queue::assertNothingPushed();
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
        Livewire::test(UploadChurchService::class)->assertForbidden();
        Livewire::test(ShowChurchService::class, ['churchService' => $service])->assertForbidden();
    }
}
