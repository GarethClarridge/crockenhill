<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin\ChurchServices;

use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Livewire\Admin\ChurchServices\ListSectionPublications;
use App\Livewire\Admin\ChurchServices\ProcessingReviewList;
use App\Livewire\Admin\ChurchServices\ShowChurchService;
use App\Livewire\Admin\ChurchServices\SubmitEmailText;
use App\Mail\LivestreamProcessingFailed;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\User;
use App\Presenters\ChurchServiceShowPresenter;
use App\Services\Processing\ProcessingPipelineBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AlwaysFailingJob;
use Tests\TestCase;

class ShowChurchServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['service-tracking.enabled' => true]);
        Storage::fake('local');
        Storage::fake('public');
        config([
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.storage.transcript_disk' => 'local',
            'thumbnail-generation.storage.disk' => 'public',
        ]);

        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    // -------------------------------------------------------------------------
    // Failure parity
    // -------------------------------------------------------------------------

    #[Test]
    public function it_builds_the_service_show_read_model_outside_the_livewire_component(): void
    {
        $processingId = '55555555-5555-5555-5555-555555555555';

        $churchService = ChurchService::factory()->create([
            'date' => '2026-05-03',
            'service' => SermonService::Morning->value,
            'pending_structure_merge_source' => null,
            'import_metadata' => [
                'confidence_score' => 0.52,
                'warnings' => ['Check the incoming order before publishing.'],
                'livestream_projection' => [
                    'processing_id' => $processingId,
                ],
                'pending_structure_merge' => [
                    'incoming_source' => 'openlp',
                    'created_at' => now()->toIso8601String(),
                    'confidence' => ['current' => 'high', 'incoming' => 'medium'],
                    'conflicts' => [
                        ['type' => 'title_conflict'],
                    ],
                    'proposed_items' => [
                        ['position' => 1, 'type' => 'songs', 'title' => 'Opening song'],
                    ],
                    'classification' => ['auto_merge' => [], 'review_required' => [0], 'unmatched_incoming' => []],
                ],
            ],
        ]);

        ChurchServiceItem::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'title' => 'Sermon',
            'livestream_processing_id' => $processingId,
        ]);

        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_id' => $processingId,
            'church_service_id' => null,
            'extracted_date' => null,
            'extracted_service' => null,
        ]);

        $readModel = app(ChurchServiceShowPresenter::class)->present($churchService);

        $this->assertSame(['Check the incoming order before publishing.'], $readModel->warnings);
        $this->assertSame(0.52, $readModel->confidenceScore);
        $this->assertCount(1, $readModel->processingRunViews);
        $this->assertTrue($readModel->processingRunViews[0]->run->is($run));
        $this->assertNull($readModel->pendingMerge);
        $this->assertNull($readModel->pendingMergeSource);

        $churchService->forceFill(['pending_structure_merge_source' => 'openlp'])->save();

        $freshChurchService = $churchService->fresh();
        $this->assertInstanceOf(ChurchService::class, $freshChurchService);

        $readModel = app(ChurchServiceShowPresenter::class)->present($freshChurchService);

        $this->assertNotNull($readModel->pendingMerge);
        $this->assertSame('openlp', $readModel->pendingMergeSource);
    }

    #[Test]
    public function church_service_workflow_pages_use_the_shared_admin_composition_components(): void
    {
        config(['media-processing.section_publishing.enabled' => true]);

        Livewire::actingAs($this->admin)
            ->test(ListSectionPublications::class)
            ->assertSeeHtml('id="admin-list-results"')
            ->assertSeeHtml('wire:loading.class.delay.200ms="opacity-50"')
            ->assertSee('No section publications found');

        Livewire::actingAs($this->admin)
            ->test(ProcessingReviewList::class)
            ->assertSeeHtml('id="admin-list-results"')
            ->assertSeeHtml('wire:loading.class.delay.200ms="opacity-50"')
            ->assertSee('No sermon processing runs');

        Livewire::actingAs($this->admin)
            ->test(SubmitEmailText::class)
            ->assertSee('Import Email Text')
            ->assertSeeHtml('wire:target="submit"');
    }

    #[Test]
    public function it_marks_the_run_as_failed_and_sends_notification_when_reclassification_chain_fails(): void
    {
        Mail::fake();
        config(['queue.default' => 'sync']);

        $serviceDate = '2026-01-15';
        $serviceType = SermonService::Morning;

        $churchService = ChurchService::factory()->create([
            'date' => $serviceDate,
            'service' => $serviceType->value,
        ]);

        Storage::disk('local')->put('livestreams/2026/service.mp4', 'fake-video');

        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => 'livestreams/2026/service.mp4',
            'extracted_date' => $serviceDate,
            'extracted_service' => $serviceType,
        ]);

        $builder = $this->mock(ProcessingPipelineBuilder::class);
        $builder->shouldReceive('buildSectionReclassificationChainJobs')->andReturn([new AlwaysFailingJob]);

        try {
            Livewire::actingAs($this->admin)
                ->test(ShowChurchService::class, ['churchService' => $churchService])
                ->call('reclassify', $log->id);
        } catch (\RuntimeException) {
            // Sync queue re-throws after firing the catch callback — expected.
        }

        $log->refresh();
        $this->assertSame(ProcessingStatus::Failed, $log->status);
        $this->assertNotNull($log->error_message);
        $this->assertNotNull($log->completed_at);
        Mail::assertQueued(LivestreamProcessingFailed::class, fn ($mail) => $mail->processingId === $log->processing_id);
    }

    #[Test]
    public function it_can_delete_a_broken_upload_from_the_service_page_and_redirect_when_the_service_is_removed(): void
    {
        $processingId = '33333333-3333-3333-3333-333333333333';

        $churchService = ChurchService::factory()->create([
            'date' => '2026-04-20',
            'service' => SermonService::Morning->value,
            'source' => 'livestream',
            'needs_review' => true,
            'import_metadata' => [
                'livestream_projection' => [
                    'projected_at' => now()->toIso8601String(),
                ],
            ],
        ]);

        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_id' => $processingId,
            'status' => ProcessingStatus::Completed,
            'church_service_id' => null,
            'extracted_date' => '2026-04-20',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $sermon = Sermon::factory()->fromLivestream()->create([
            'livestream_processing_id' => $processingId,
            'audio_file_path' => 'sermons/audio/'.$processingId.'_sermon.mp3',
        ]);

        $log->update([
            'sermon_id' => $sermon->id,
            'audio_file_path' => $sermon->audio_file_path,
        ]);

        ChurchServiceItem::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'title' => 'Sermon',
            'livestream_processing_id' => $processingId,
            'metadata' => [
                'livestream_projection' => [
                    'confidence_level' => 'high',
                ],
            ],
        ]);

        Storage::disk('public')->put('sermons/audio/'.$processingId.'_sermon.mp3', 'audio');

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $churchService])
            ->call('deleteUpload', $log->id)
            ->assertRedirect(route('admin.services.index'));

        $this->assertDatabaseMissing('media_processing_logs', ['id' => $log->id]);
        $this->assertDatabaseMissing('sermons', ['id' => $sermon->id]);
        $this->assertDatabaseMissing('church_services', ['id' => $churchService->id]);
    }

    #[Test]
    public function it_shows_repaired_runs_found_via_item_projection_columns(): void
    {
        $processingId = '44444444-4444-4444-4444-444444444444';

        $churchService = ChurchService::factory()->create([
            'date' => '2026-04-27',
            'service' => SermonService::Morning->value,
            'source' => 'livestream',
            'import_metadata' => [
                'livestream_projection' => [
                    'projected_at' => now()->toIso8601String(),
                ],
            ],
        ]);

        ChurchServiceItem::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'title' => 'Sermon',
            'livestream_processing_id' => $processingId,
            'metadata' => [
                'livestream_projection' => [
                    'confidence_level' => 'high',
                ],
            ],
        ]);

        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_id' => $processingId,
            'church_service_id' => null,
            'extracted_date' => null,
            'extracted_service' => null,
            'processing_metadata' => [
                'repair' => [
                    'original_extracted_date' => '2026-04-27',
                    'original_extracted_service' => SermonService::Morning->value,
                ],
            ],
        ]);

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $churchService])
            ->assertSee($log->processing_id)
            ->assertSee('Reclassify')
            ->assertSee('Delete upload');
    }

    // -------------------------------------------------------------------------
    // Inline section review (P3.2)
    // -------------------------------------------------------------------------

    /**
     * @return array{0: ChurchService, 1: MediaProcessingLog}
     */
    private function workbenchServiceWithRun(): array
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning,
            'needs_review' => false,
        ]);

        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
            'sermon_id' => null,
        ]);

        return [$service, $run];
    }

    #[Test]
    public function it_renders_inline_review_panels_for_flagged_sections(): void
    {
        [$service, $run] = $this->workbenchServiceWithRun();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song->value,
            'title' => 'Flagged Song',
            'needs_manual_review' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('Manual review')
            ->assertSee('Section type')
            ->assertSee('Section title')
            ->assertSeeHtml('id="section-'.$section->id.'"')
            ->assertSeeHtml('wire:click="saveSection('.$section->id.')"')
            ->assertSet('sectionEdits.'.$section->id.'.title', 'Flagged Song');
    }

    #[Test]
    public function it_does_not_seed_edit_state_for_clean_sections(): void
    {
        [$service, $run] = $this->workbenchServiceWithRun();

        $clean = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'needs_manual_review' => false,
            'confidence' => 1.0,
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service]);

        $this->assertArrayNotHasKey($clean->id, $component->get('sectionEdits'));
    }

    #[Test]
    public function it_saves_section_edits_inline_on_the_workbench(): void
    {
        [$service, $run] = $this->workbenchServiceWithRun();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song->value,
            'title' => 'Original Title',
            'needs_manual_review' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->set("sectionEdits.{$section->id}.section_type", ServiceSectionType::Prayer->value)
            ->set("sectionEdits.{$section->id}.title", 'Updated Title')
            ->call('saveSection', $section->id)
            ->assertDispatched('notify', type: 'success', message: 'Section changes saved.');

        $section->refresh();
        $this->assertSame(ServiceSectionType::Prayer, $section->section_type);
        $this->assertSame('Updated Title', $section->title);
    }

    #[Test]
    public function it_merges_adjacent_sections_inline_on_the_workbench(): void
    {
        Queue::fake();

        [$service, $run] = $this->workbenchServiceWithRun();

        $first = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'needs_manual_review' => true,
            'start_time' => 100.0,
            'end_time' => 107.0,
        ]);

        $second = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 2,
            'needs_manual_review' => true,
            'start_time' => 107.0,
            'end_time' => 231.0,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('Merge with the next Song section')
            ->call('initiateMerge', $first->id, $second->id)
            ->assertSee('Merge these two Song sections into one?')
            ->call('confirmMerge')
            ->assertDispatched('notify', type: 'success', message: 'Sections merged successfully.');

        // The action keeps the longer section and absorbs the shorter one.
        $second->refresh();
        $this->assertSame(100.0, $second->start_time);
        $this->assertSame(231.0, $second->end_time);
        $this->assertDatabaseMissing('service_sections', ['id' => $first->id]);
    }

    #[Test]
    public function workbench_mutating_actions_require_admin(): void
    {
        [$service, $run] = $this->workbenchServiceWithRun();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'needs_manual_review' => true,
        ]);

        $member = User::factory()->create(['is_admin' => false]);

        foreach ([
            fn ($component) => $component->call('saveSection', $section->id),
            fn ($component) => $component->call('approvePendingPublications', $service->id),
            // The confirmMerge() authorization gap from the dashboard must not be copied (C4).
            fn ($component) => $component->call('initiateMerge', $section->id, $section->id)->call('confirmMerge'),
            fn ($component) => $component->call('markServiceReviewed', $service->id),
        ] as $invoke) {
            $invoke(
                Livewire::actingAs($member)->test(ShowChurchService::class, ['churchService' => $service])
            )->assertForbidden();
        }
    }

    #[Test]
    public function it_offers_batch_approval_when_publications_are_pending(): void
    {
        [$service, $run] = $this->workbenchServiceWithRun();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('1 pending publication')
            ->assertSee('Approve all pending publications')
            ->call('approvePendingPublications', $service->id)
            ->assertDispatched('notify');
    }

    #[Test]
    public function it_hides_publication_affordances_when_section_publishing_is_disabled(): void
    {
        config(['media-processing.section_publishing.enabled' => false]);

        [$service, $run] = $this->workbenchServiceWithRun();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'needs_manual_review' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->assertDontSee('Approve all pending publications')
            ->assertDontSeeHtml('wire:click="approve('.$section->id.')"');
    }
}
