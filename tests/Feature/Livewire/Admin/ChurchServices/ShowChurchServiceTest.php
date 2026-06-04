<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin\ChurchServices;

use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Livewire\Admin\ChurchServices\ListSectionPublications;
use App\Livewire\Admin\ChurchServices\ProcessingReviewList;
use App\Livewire\Admin\ChurchServices\ShowChurchService;
use App\Livewire\Admin\ChurchServices\SubmitEmailText;
use App\Mail\LivestreamProcessingFailed;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\User;
use App\Presenters\ChurchServiceShowPresenter;
use App\Services\Processing\ProcessingPipelineBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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
            ->assertSee('Submit email text')
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
}
