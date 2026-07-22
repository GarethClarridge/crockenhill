<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin\ChurchServices;

use App\Actions\ConfirmLivestreamSermonSegment;
use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\PublishApprovedServiceSection;
use App\Livewire\Admin\ChurchServices\ShowChurchService;
use App\Livewire\Admin\ChurchServices\SubmitEmailText;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\User;
use App\Presenters\ChurchServiceShowPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
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
            ->test(SubmitEmailText::class)
            ->assertSee('Import email text')
            ->assertSeeHtml('wire:target="submit"');
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
            ->assertDontSee('Reclassify')
            ->assertSee('Delete upload');
    }

    // -------------------------------------------------------------------------
    // Inline section review (P3.2)
    // -------------------------------------------------------------------------

    #[Test]
    public function the_workbench_renders_auto_trim_video_runs_and_their_sections(): void
    {
        [$service] = $this->workbenchServiceWithRun();

        // Auto-trim video runs produce service sections through the same
        // segmentation pipeline as livestreams, so the workbench renders
        // their run cards and review panels …
        $autoTrimRun = MediaProcessingLog::factory()->video()->completed()->create([
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
            'sermon_id' => null,
            'processing_metadata' => [
                'video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
            ],
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $autoTrimRun->id,
            'church_service_item_id' => null,
            'title' => 'Auto Trim Flagged Section',
            'needs_manual_review' => true,
        ]);

        // … while plain (full_video) runs stay off the service page.
        $plainVideoRun = MediaProcessingLog::factory()->video()->completed()->create([
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
            'sermon_id' => null,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->assertSeeHtml('id="processing-run-'.$autoTrimRun->id.'"')
            ->assertSee('Auto Trim Flagged Section')
            ->assertSeeHtml('section-review-panel-'.$section->id)
            ->assertDontSeeHtml('id="processing-run-'.$plainVideoRun->id.'"');
    }

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
            // The confirmMerge() authorization gap from the dashboard must not be copied (C4),
            // and the pending-merge state setters are guarded too.
            fn ($component) => $component->call('initiateMerge', $section->id, $section->id),
            fn ($component) => $component->call('confirmMerge'),
            fn ($component) => $component->call('cancelMerge'),
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

    // -------------------------------------------------------------------------
    // Embedded segment confirmation (P3.3)
    // -------------------------------------------------------------------------

    private function pausedRunForService(ChurchService $service): MediaProcessingLog
    {
        Storage::disk('local')->put('livestreams/2026/paused.mp4', 'fake-video');

        return MediaProcessingLog::factory()
            ->livestream()
            ->manualReviewRequired('multiple_qualifying_speech_blocks')
            ->create([
                'source_file_path' => 'livestreams/2026/paused.mp4',
                'extracted_date' => $service->date->toDateString(),
                'extracted_service' => $service->service->value,
            ]);
    }

    #[Test]
    public function it_embeds_segment_confirmation_for_runs_paused_on_manual_review(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning,
        ]);

        $run = $this->pausedRunForService($service);

        $speechSegment = LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $run->id,
            'segment_index' => 0,
            'is_sermon_candidate' => true,
        ]);

        LivestreamSegment::factory()->create([
            'media_processing_log_id' => $run->id,
            'segment_index' => 1,
            'classification' => 'song',
        ]);

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('Confirm the sermon segment')
            ->assertSee('This is the sermon')
            ->assertSeeHtml('confirmRunSegment('.$run->id.', '.$speechSegment->id.')');
    }

    #[Test]
    public function it_confirms_a_sermon_segment_inline_on_the_workbench(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning,
        ]);

        $run = $this->pausedRunForService($service);

        $speechSegment = LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $run->id,
            'segment_index' => 0,
        ]);

        $this->mock(ConfirmLivestreamSermonSegment::class, function ($mock) use ($run, $speechSegment): void {
            $mock->shouldReceive('execute')
                ->once()
                ->with($run->processing_id, $speechSegment->id, \Mockery::type(User::class));
        });

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->call('confirmRunSegment', $run->id, $speechSegment->id)
            ->assertDispatched('notify', type: 'success')
            ->assertNoRedirect();
    }

    #[Test]
    public function it_rejects_segment_confirmation_for_runs_that_do_not_belong_to_the_service(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning,
        ]);

        $otherService = ChurchService::factory()->create([
            'date' => '2026-05-31',
            'service' => SermonService::Morning,
        ]);

        $foreignRun = $this->pausedRunForService($otherService);

        $segment = LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $foreignRun->id,
            'segment_index' => 0,
        ]);

        $this->mock(ConfirmLivestreamSermonSegment::class, function ($mock): void {
            $mock->shouldNotReceive('execute');
        });

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->call('confirmRunSegment', $foreignRun->id, $segment->id)
            ->assertDispatched('notify', type: 'error', message: 'Selected run does not belong to this service.');
    }

    #[Test]
    public function it_rejects_segment_confirmation_when_the_run_is_not_awaiting_review(): void
    {
        [$service, $run] = $this->workbenchServiceWithRun();

        $segment = LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $run->id,
            'segment_index' => 0,
        ]);

        $this->mock(ConfirmLivestreamSermonSegment::class, function ($mock): void {
            $mock->shouldNotReceive('execute');
        });

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->call('confirmRunSegment', $run->id, $segment->id)
            ->assertDispatched('notify', type: 'error', message: 'This run is not awaiting sermon-segment confirmation.');
    }

    #[Test]
    public function confirm_run_segment_requires_admin(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning,
        ]);

        $run = $this->pausedRunForService($service);

        $segment = LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $run->id,
            'segment_index' => 0,
        ]);

        $member = User::factory()->create(['is_admin' => false]);

        Livewire::actingAs($member)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->call('confirmRunSegment', $run->id, $segment->id)
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Migrated from AdminServiceReviewDashboardTest (P3.4) — the dashboard is
    // retired; these behaviours now live on the workbench.
    // -------------------------------------------------------------------------

    #[Test]
    public function it_renders_review_reason_chips_distinguishing_song_match_types(): void
    {
        [$service, $run] = $this->workbenchServiceWithRun();

        $songItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Opening Song',
            'song_id' => null,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $songItem->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'title' => 'Unknown Song',
            'needs_manual_review' => true,
            'confidence' => 0.72,
            'song_match_type' => 'unmatched',
            'metadata' => [
                'review_reason' => 'expected_type_mismatch',
                'oos_alignment' => ['song_match_type' => 'unmatched'],
            ],
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 2,
            'title' => 'Inferred Song',
            'needs_manual_review' => true,
            'confidence' => 0.72,
            'song_match_type' => 'inferred',
            'metadata' => [
                'review_reason' => 'song_alignment_inferred',
                'oos_alignment' => ['song_match_type' => 'inferred'],
            ],
        ]);

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('Unknown Song')
            ->assertSee('Manual review')
            ->assertSee('Pending approval')
            ->assertSee('Low confidence')
            ->assertSee('Unmatched song')
            ->assertSee('Inferred song label');
    }

    #[Test]
    public function it_renders_each_review_reason_once_on_the_flow_row_header(): void
    {
        [$service, $run] = $this->workbenchServiceWithRun();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'title' => 'Flagged song',
            'needs_manual_review' => true,
            'confidence' => 0.72,
            'metadata' => ['review_reason' => 'structure_low_confidence'],
        ]);

        $html = Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->html();

        $this->assertSame(1, substr_count($html, 'Manual review'));
        $this->assertSame(1, substr_count($html, 'Low confidence'));
        $this->assertStringNotContainsString('Review reason', $html);
    }

    #[Test]
    public function it_does_not_seed_edit_buffers_for_review_rows_created_after_mount(): void
    {
        [$service, $run] = $this->workbenchServiceWithRun();

        $existingSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Welcome->value,
            'section_order' => 1,
            'title' => 'Existing review row',
            'needs_manual_review' => true,
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('Existing review row');

        $this->assertArrayHasKey($existingSection->id, $component->get('sectionEdits'));

        $newSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Prayer->value,
            'section_order' => 2,
            'title' => 'New review row',
            'needs_manual_review' => true,
        ]);

        $component
            ->call('$refresh')
            ->assertSee('New review row');

        $this->assertArrayNotHasKey($newSection->id, $component->get('sectionEdits'));
    }

    #[Test]
    public function approve_and_reject_actions_update_publication_status_on_the_workbench(): void
    {
        Queue::fake();

        [$service, $run] = $this->workbenchServiceWithRun();

        $approveSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Welcome->value,
            'section_order' => 1,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'extracted_video_path' => 'sermons/sections/'.$run->id.'/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-'.$run->id.'.mp3',
        ]);

        $rejectSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Welcome->value,
            'section_order' => 2,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
        ]);

        Storage::disk('public')->put('sermons/sections/'.$run->id.'/video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/section-'.$run->id.'.mp3', 'audio');

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->call('approve', $approveSection->id)
            ->assertDispatched('notify', type: 'success', message: 'Section approved and publish job queued.')
            ->call('reject', $rejectSection->id)
            ->assertDispatched('notify', type: 'success', message: 'Section rejected.');

        $this->assertSame(ServiceSectionPublicationStatus::Approved, $approveSection->fresh()->publication_status);
        $this->assertSame(ServiceSectionPublicationStatus::Rejected, $rejectSection->fresh()->publication_status);
        Queue::assertPushed(PublishApprovedServiceSection::class);
    }

    #[Test]
    public function requeue_moves_a_rejected_section_back_to_pending_on_the_workbench(): void
    {
        [$service, $run] = $this->workbenchServiceWithRun();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'publication_status' => ServiceSectionPublicationStatus::Rejected->value,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->call('requeue', $section->id)
            ->assertDispatched('notify', type: 'success', message: 'Section moved back to pending approval.');

        $this->assertSame(ServiceSectionPublicationStatus::PendingApproval, $section->fresh()->publication_status);
    }

    #[Test]
    public function a_rejected_section_with_no_review_flags_still_offers_requeue(): void
    {
        [$service, $run] = $this->workbenchServiceWithRun();

        // Rejection is not a review reason, so this section gets no review
        // panel — but the timeline row must still offer the only path back
        // into the approval queue.
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Welcome->value,
            'church_service_item_id' => null,
            'needs_manual_review' => false,
            'confidence' => 0.98,
            'publication_status' => ServiceSectionPublicationStatus::Rejected->value,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->assertDontSeeHtml('section-review-panel-'.$section->id)
            ->assertSee('This section was rejected. Requeue it to send it back for approval.')
            ->assertSee('Requeue');

        config(['media-processing.section_publishing.enabled' => false]);

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->assertDontSee('Requeue');
    }

    #[Test]
    public function save_section_requires_valid_type_and_title(): void
    {
        [$service, $run] = $this->workbenchServiceWithRun();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'needs_manual_review' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->set("sectionEdits.{$section->id}.section_type", 'invalid')
            ->set("sectionEdits.{$section->id}.title", '')
            ->call('saveSection', $section->id)
            ->assertHasErrors(['section_type', 'title']);
    }

    #[Test]
    public function it_blocks_saving_a_section_that_is_no_longer_in_the_review_queue(): void
    {
        [$service, $run] = $this->workbenchServiceWithRun();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'needs_manual_review' => false,
            'confidence' => 0.98,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'section_type' => ServiceSectionType::Welcome->value,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->set("sectionEdits.{$section->id}.section_type", ServiceSectionType::Prayer->value)
            ->set("sectionEdits.{$section->id}.title", 'Updated Title')
            ->call('saveSection', $section->id)
            ->assertDispatched('notify', type: 'error', message: 'Section is no longer awaiting review.');

        $section->refresh();
        $this->assertSame(ServiceSectionType::Welcome, $section->section_type);
        $this->assertNotSame('Updated Title', $section->title);
    }

    #[Test]
    public function it_rejects_merging_sections_from_different_processing_runs(): void
    {
        Queue::fake();

        [$service, $runA] = $this->workbenchServiceWithRun();

        $runB = MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
            'sermon_id' => null,
        ]);

        $first = ServiceSection::factory()->create([
            'media_processing_log_id' => $runA->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'needs_manual_review' => true,
            'start_time' => 100.0,
            'end_time' => 107.0,
        ]);

        $second = ServiceSection::factory()->create([
            'media_processing_log_id' => $runB->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'needs_manual_review' => true,
            'start_time' => 107.0,
            'end_time' => 231.0,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->call('initiateMerge', $first->id, $second->id)
            ->call('confirmMerge')
            ->assertDispatched('notify', type: 'error', message: 'Both sections must belong to the same processing run.');

        $this->assertDatabaseHas('service_sections', ['id' => $first->id]);
        $this->assertDatabaseHas('service_sections', ['id' => $second->id]);
    }

    #[Test]
    public function it_rejects_merging_sections_of_different_types(): void
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
            'section_type' => ServiceSectionType::BibleReading->value,
            'section_order' => 2,
            'needs_manual_review' => true,
            'start_time' => 107.0,
            'end_time' => 231.0,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->call('initiateMerge', $first->id, $second->id)
            ->call('confirmMerge')
            ->assertDispatched('notify', type: 'error', message: 'Both sections must have the same section type.');
    }

    #[Test]
    public function it_rejects_merging_a_published_section(): void
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
            'publication_status' => ServiceSectionPublicationStatus::Published->value,
        ]);

        $second = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 2,
            'needs_manual_review' => true,
            'start_time' => 107.0,
            'end_time' => 231.0,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->call('initiateMerge', $first->id, $second->id)
            ->call('confirmMerge')
            ->assertDispatched('notify', type: 'error', message: 'Published sections cannot be merged.');
    }

    #[Test]
    public function it_rejects_merging_when_another_section_sits_between_the_candidates(): void
    {
        Queue::fake();

        [$service, $run] = $this->workbenchServiceWithRun();

        $first = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 5,
            'needs_manual_review' => true,
            'start_time' => 100.0,
            'end_time' => 107.0,
        ]);

        // Un-flagged section at order 6 (between the merge candidates by section_order)
        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Prayer->value,
            'section_order' => 6,
            'needs_manual_review' => false,
            'start_time' => 300.0,
            'end_time' => 400.0,
        ]);

        // Starts close to $first (gap 1.5s ≤ max 2s) so the gap check passes and
        // the between-sections check triggers instead.
        $third = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 7,
            'needs_manual_review' => true,
            'start_time' => 108.5,
            'end_time' => 244.0,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->call('initiateMerge', $first->id, $third->id)
            ->call('confirmMerge')
            ->assertDispatched('notify', type: 'error', message: 'There are other sections between these two — they cannot be merged.');

        $this->assertDatabaseHas('service_sections', ['id' => $first->id]);
        $this->assertDatabaseHas('service_sections', ['id' => $third->id]);
    }

    #[Test]
    public function it_saves_speaker_edits_for_childrens_talk_sections(): void
    {
        [$service, $run] = $this->workbenchServiceWithRun();

        $preacher = Preacher::factory()->create(['name' => 'Rev Override']);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'title' => "Children's Talk",
            'needs_manual_review' => true,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'extracted_video_path' => 'sermons/sections/speaker-test/video.mp4',
            'extracted_audio_path' => 'sermons/audio/speaker-test.mp3',
            'metadata' => [
                'review_reason' => 'childrens_talk_speaker_ambiguous',
                'childrens_talk_speaker' => [
                    'predicted' => ['outcome' => 'ambiguous', 'preacher_name' => 'Unknown', 'confidence' => 0.5, 'reason' => 'Too close to call.'],
                ],
            ],
        ]);

        Storage::disk('public')->put('sermons/sections/speaker-test/video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/speaker-test.mp3', 'audio');

        Livewire::actingAs($this->admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->set("speakerEdits.{$section->id}.preacher_id", (string) $preacher->id)
            ->set("speakerEdits.{$section->id}.speaker_name", '')
            ->call('saveSection', $section->id)
            ->assertDispatched('notify', type: 'success', message: 'Section changes saved.');

        $section->refresh();

        $this->assertFalse($section->needs_manual_review);
        $this->assertSame(ServiceSectionPublicationStatus::PendingApproval, $section->publication_status);
        $this->assertSame('Rev Override', $section->metadata['childrens_talk_speaker']['reviewed']['preacher_name'] ?? null);
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
