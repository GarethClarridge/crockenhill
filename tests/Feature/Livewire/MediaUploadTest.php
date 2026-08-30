<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Data\ProcessingResult;
use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Enums\UploadState;
use App\Jobs\AnalyzeSegments;
use App\Jobs\AssessSermonVideoQuality;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\CreateSermonTranscriptFromService;
use App\Jobs\DetectServiceStructure;
use App\Jobs\EnhanceAudio;
use App\Jobs\ExtractSermon;
use App\Jobs\GenerateThumbnail;
use App\Jobs\IdentifySpeaker;
use App\Jobs\MatchSongsFromTranscript;
use App\Jobs\MergeSongContinuations;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\ProjectLivestreamServiceStructure;
use App\Jobs\PromoteHistoricAssets;
use App\Jobs\SendCompletionNotification;
use App\Jobs\SubmitToProcessing;
use App\Jobs\TranscribeFullService;
use App\Livewire\Admin\MediaUpload;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\User;
use App\Services\Media\Video\VideoSegmentationService;
use App\Services\Media\Video\VideoStorageService;
use App\Services\Processing\UnifiedMediaProcessor;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaUploadTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->crockenhillAdmin()->create();

        // Fake disks used by the component
        Storage::fake('local');
        Storage::disk('local')->makeDirectory('livewire-tmp');
    }

    #[Test]
    public function it_renders_successfully()
    {
        $this->actingAs($this->admin);

        Livewire::test(MediaUpload::class)
            ->assertStatus(200)
            ->assertSee('Upload recording');
    }

    #[Test]
    public function the_back_link_avoids_the_services_hub_when_service_tracking_is_disabled(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(MediaUpload::class)
            ->assertSeeHtml(route('admin.services.index'));

        // The uploader stays reachable in this state (contract C5) but the
        // services hub 404s, so the back link must not point at it.
        config(['service-tracking.enabled' => false]);

        Livewire::test(MediaUpload::class)
            ->assertSeeHtml(route('members.home'))
            ->assertDontSeeHtml(route('admin.services.index'));
    }

    #[Test]
    public function it_requires_authentication_and_admin_permissions()
    {
        // Unauthenticated — both the legacy redirect and the new page bounce to login
        $this->get(route('admin.sermon-upload.create'))
            ->assertRedirect(route('login'));

        $this->get(route('admin.services.upload-recording'))
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function it_validates_media_type_selection()
    {
        $this->actingAs($this->admin);
        $file = UploadedFile::fake()->create('sermon.mp3', 1024);

        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'invalid-type')
            ->set('mediaFile', $file)
            ->call('uploadComplete')
            ->assertHasErrors(['mediaType' => 'Invalid media type selected.']);
    }

    #[Test]
    public function it_handles_missing_file_on_upload_complete()
    {
        $this->actingAs($this->admin);

        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'audio')
            ->set('mediaFile', null)
            ->call('uploadComplete')
            ->assertSet('status', UploadState::Failed)
            ->assertSet('statusMessageOverride', 'File upload completed but file is missing');
    }

    #[Test]
    public function it_validates_file_requirements()
    {
        $this->actingAs($this->admin);

        $invalidFile = UploadedFile::fake()->create('test.txt', 100);
        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'audio')
            ->set('mediaFile', $invalidFile)
            ->call('uploadComplete')
            ->assertHasErrors(['mediaFile'])
            ->assertSet('processingId', null)
            ->assertSet('tempFilePath', null)
            ->assertSeeHtml('id="media-file"');
    }

    #[Test]
    public function it_starts_processing_after_successful_upload()
    {
        $this->actingAs($this->admin);

        $expectedId = '00000000-0000-0000-0000-000000000000';

        Storage::fake('local');
        $file = UploadedFile::fake()->create('sermon.mp3', 1024);

        $mockResult = ProcessingResult::success($expectedId, 'Started');

        $mockProcessor = $this->createMock(UnifiedMediaProcessor::class);
        $mockProcessor->expects($this->once())
            ->method('process')
            ->willReturn($mockResult);

        $this->app->instance(UnifiedMediaProcessor::class, $mockProcessor);

        $test = Livewire::test(MediaUpload::class)
            ->set('mediaType', 'audio')
            ->set('mediaFile', $file)
            ->call('uploadComplete');

        if ($test->get('status') === UploadState::Failed) {
            $this->fail($test->get('statusMessage'));
        }

        $test->assertSet('status', UploadState::Processing)
            ->assertSet('processingId', $expectedId)
            ->assertSet('statusMessageOverride', 'Upload complete. Processing has started.');
    }

    #[Test]
    public function it_passes_auto_trim_video_options_when_enabled_for_a_video_upload(): void
    {
        $this->actingAs($this->admin);

        $expectedId = '00000000-0000-0000-0000-000000000321';
        $file = UploadedFile::fake()->create('sermon.mp4', 2048, 'video/mp4');

        $mockResult = ProcessingResult::success($expectedId, 'Started');

        $mockProcessor = $this->createMock(UnifiedMediaProcessor::class);
        $mockProcessor->expects($this->once())
            ->method('process')
            ->with(
                'video',
                $this->isInstanceOf(UploadedFile::class),
                null,
                [
                    'auto_trim' => true,
                    'video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
                ],
                SermonService::Evening,
            )
            ->willReturn($mockResult);

        $this->app->instance(UnifiedMediaProcessor::class, $mockProcessor);

        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'video')
            ->set('autoTrimVideo', true)
            ->set('mediaFile', $file)
            ->call('uploadComplete')
            ->assertSet('processingId', $expectedId)
            ->assertSet('status', UploadState::Processing);
    }

    #[Test]
    public function it_defaults_the_service_per_media_type(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'livestream')
            ->assertSet('serviceOverride', SermonService::Morning->value)
            ->set('mediaType', 'video')
            ->assertSet('serviceOverride', SermonService::Evening->value)
            ->set('mediaType', 'audio')
            ->assertSet('serviceOverride', SermonService::Evening->value);
    }

    #[Test]
    public function service_context_prefills_the_service_and_passes_its_date_to_the_processor(): void
    {
        $this->actingAs($this->admin);

        $churchService = ChurchService::factory()->create([
            'date' => '2026-02-22',
            'service' => SermonService::Morning,
        ]);
        $file = UploadedFile::fake()->create('2025-12-14-evening-sermon.mp3', 1024);

        $processor = $this->createMock(UnifiedMediaProcessor::class);
        $processor->expects($this->once())
            ->method('process')
            ->with(
                'audio',
                $this->isInstanceOf(UploadedFile::class),
                null,
                [],
                SermonService::Morning,
                '2026-02-22',
            )
            ->willReturn(ProcessingResult::success('00000000-0000-0000-0000-000000000998', 'Started'));

        $this->app->instance(UnifiedMediaProcessor::class, $processor);

        Livewire::withQueryParams(['churchServiceId' => (string) $churchService->id])
            ->test(MediaUpload::class)
            ->assertSet('churchServiceId', $churchService->id)
            ->assertSet('serviceOverride', SermonService::Morning->value)
            ->assertSee('Uploading for')
            ->assertSee('22 February 2026 — Morning')
            ->assertSee('Wrong service?')
            ->set('mediaType', 'audio')
            ->set('mediaFile', $file)
            ->call('uploadComplete')
            ->assertSet('processingId', '00000000-0000-0000-0000-000000000998');
    }

    #[Test]
    public function it_passes_the_selected_service_override_to_the_processor(): void
    {
        $this->actingAs($this->admin);

        $expectedId = '00000000-0000-0000-0000-000000000999';
        $file = UploadedFile::fake()->create('sermon.mp3', 1024);

        $mockProcessor = $this->createMock(UnifiedMediaProcessor::class);
        $mockProcessor->expects($this->once())
            ->method('process')
            ->with(
                'audio',
                $this->isInstanceOf(UploadedFile::class),
                null,
                [],
                SermonService::Morning,
            )
            ->willReturn(ProcessingResult::success($expectedId, 'Started'));

        $this->app->instance(UnifiedMediaProcessor::class, $mockProcessor);

        // Audio defaults to evening; operator overrides to morning before submitting.
        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'audio')
            ->set('serviceOverride', SermonService::Morning->value)
            ->set('mediaFile', $file)
            ->call('uploadComplete')
            ->assertSet('processingId', $expectedId)
            ->assertSet('status', UploadState::Processing);
    }

    #[Test]
    public function it_validates_that_a_service_is_selected(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'audio')
            ->set('serviceOverride', '')
            ->set('mediaFile', UploadedFile::fake()->create('sermon.mp3', 1024))
            ->call('uploadComplete')
            ->assertHasErrors(['serviceOverride' => 'Please select which service this recording is for.']);
    }

    #[Test]
    public function it_handles_processing_failures_gracefully()
    {
        $this->actingAs($this->admin);

        $file = UploadedFile::fake()->create('sermon.mp3', 1024);

        $mockResult = ProcessingResult::failure('test-proc-id', 'System error', 'ERR_CODE');

        $mockProcessor = $this->createMock(UnifiedMediaProcessor::class);
        $mockProcessor->expects($this->once())
            ->method('process')
            ->willReturn($mockResult);

        $this->app->instance(UnifiedMediaProcessor::class, $mockProcessor);

        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'audio')
            ->set('mediaFile', $file)
            ->call('uploadComplete')
            ->assertSet('status', UploadState::Failed)
            ->assertSet('statusMessageOverride', 'System error');
    }

    #[Test]
    public function it_ignores_duplicate_upload_complete_triggers_for_the_same_file(): void
    {
        $this->actingAs($this->admin);

        $expectedId = '00000000-0000-0000-0000-000000000123';
        $file = UploadedFile::fake()->create('sermon.mp3', 1024);

        $mockResult = ProcessingResult::success($expectedId, 'Started');

        $mockProcessor = $this->createMock(UnifiedMediaProcessor::class);
        $mockProcessor->expects($this->once())
            ->method('process')
            ->willReturn($mockResult);

        $this->app->instance(UnifiedMediaProcessor::class, $mockProcessor);

        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'audio')
            ->set('mediaFile', $file)
            ->call('uploadComplete')
            ->call('uploadComplete')
            ->assertSet('processingId', $expectedId)
            ->assertSet('status', UploadState::Processing);
    }

    #[Test]
    public function it_can_cancel_upload()
    {
        $this->actingAs($this->admin);

        Livewire::test(MediaUpload::class)
            ->set('status', UploadState::Uploading)
            ->call('cancelUpload')
            ->assertSet('status', UploadState::Idle);
    }

    #[Test]
    public function terminal_processing_states_do_not_render_a_second_file_picker(): void
    {
        $this->actingAs($this->admin);

        foreach ([UploadState::Failed, UploadState::Cancelled, UploadState::ManualReview] as $status) {
            Livewire::test(MediaUpload::class)
                ->set('mediaType', 'audio')
                ->set('processingId', 'existing-processing-run')
                ->set('status', $status)
                ->assertDontSeeHtml('id="media-file"');
        }
    }

    #[Test]
    public function it_handles_processing_cancellation_without_treating_it_as_failure(): void
    {
        $this->actingAs($this->admin);

        $mockProcessor = $this->createMock(UnifiedMediaProcessor::class);
        $mockProcessor->expects($this->once())
            ->method('cancel')
            ->with('proc-123')
            ->willReturn([
                'success' => true,
                'message' => 'Processing cancelled successfully',
            ]);

        $this->app->instance(UnifiedMediaProcessor::class, $mockProcessor);

        Livewire::test(MediaUpload::class)
            ->set('processingId', 'proc-123')
            ->set('status', UploadState::Processing)
            ->call('cancelProcessing')
            ->assertSet('status', UploadState::Cancelled)
            ->assertSet('currentStep', 'Processing cancelled')
            ->assertSet('statusMessageOverride', 'Processing was cancelled by user.');
    }

    #[Test]
    public function it_updates_to_cancelled_state_when_polling_processing_status(): void
    {
        $this->actingAs($this->admin);

        $mockProcessor = $this->createMock(UnifiedMediaProcessor::class);
        $mockProcessor->expects($this->never())
            ->method('getStatus');

        $this->app->instance(UnifiedMediaProcessor::class, $mockProcessor);

        MediaProcessingLog::factory()->livestream()->cancelled()->create([
            'processing_id' => 'proc-456',
            'owner_user_id' => $this->admin->id,
        ]);

        Livewire::test(MediaUpload::class)
            ->set('processingId', 'proc-456')
            ->set('status', UploadState::Processing)
            ->call('checkProcessingStatus')
            ->assertSet('status', UploadState::Cancelled)
            ->assertSet('currentStep', 'Processing cancelled')
            ->assertSet('progressPercentage', 0)
            ->assertSee('Processing was cancelled.');
    }

    #[Test]
    public function it_sets_the_admin_manual_review_url_when_polling_a_manual_review_failure(): void
    {
        $this->actingAs($this->admin);

        $mockProcessor = $this->createMock(UnifiedMediaProcessor::class);
        $mockProcessor->expects($this->never())
            ->method('getStatus');

        $this->app->instance(UnifiedMediaProcessor::class, $mockProcessor);

        $service = ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning,
        ]);

        $log = MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => 'proc-manual-review',
            'owner_user_id' => $this->admin->id,
            'status' => ProcessingStatus::Failed,
            'current_step' => 'manual_review_required',
            'extracted_date' => $service->date,
            'extracted_service' => $service->service,
        ]);

        $component = Livewire::test(MediaUpload::class)
            ->set('processingId', 'proc-manual-review')
            ->set('status', UploadState::Processing)
            ->call('checkProcessingStatus')
            ->assertSet('status', UploadState::ManualReview)
            ->assertSet('currentStep', 'Manual review required')
            ->assertSet('progressPercentage', 100)
            ->assertSee('Manual Review Required')
            ->assertSee('Choose segment');

        $this->assertSame(route('admin.recordings.sermon-segment', $log->processing_id), $component->get('statusUrl'));
    }

    #[Test]
    public function it_clears_file_when_media_type_changes()
    {
        $this->actingAs($this->admin);
        $file = UploadedFile::fake()->create('sermon.mp3', 1024);

        Livewire::test(MediaUpload::class)
            ->set('mediaFile', $file)
            ->set('mediaType', 'video')
            ->assertSet('mediaFile', null);
    }

    #[Test]
    public function it_dispatches_livestream_chain_with_completion_notification_from_livewire_upload(): void
    {
        $this->actingAs($this->admin);
        Bus::fake();

        $file = UploadedFile::fake()->create('livestream.mp4', 50000, 'video/mp4');

        $mockSegmentationService = $this->createStub(VideoSegmentationService::class);
        $mockSegmentationService->method('validateVideoFile')->willReturn(true);
        $mockSegmentationService->method('getVideoMetadata')->willReturn([
            'duration' => 3600.0,
            'format' => 'mp4',
            'size' => 50000,
        ]);

        $mockStorageService = $this->createStub(VideoStorageService::class);
        $mockStorageService->method('validateStorageSpace')->willReturn(true);
        $mockStorageService->method('storeUploadedVideo')->willReturn([
            'original_filename' => 'livestream.mp4',
            'temp_path' => 'livestreams/temp_livestream.mp4',
            'full_path' => storage_path('app/livestreams/temp_livestream.mp4'),
            'file_size' => 50000,
            'mime_type' => 'video/mp4',
        ]);

        Storage::put('livestreams/temp_livestream.mp4', 'fake video content');

        $this->app->instance(VideoSegmentationService::class, $mockSegmentationService);
        $this->app->instance(VideoStorageService::class, $mockStorageService);

        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'livestream')
            ->set('mediaFile', $file)
            ->call('uploadComplete')
            ->assertSet('status', UploadState::Processing)
            ->assertSet('statusMessageOverride', 'Upload complete. Processing has started.');

        $pendingBatch = null;

        Bus::assertBatched(function (PendingBatch $batch) use (&$pendingBatch) {
            $pendingBatch = $batch;

            return true;
        });

        $this->assertNotNull($pendingBatch);

        $thenCallbacks = $pendingBatch->thenCallbacks();
        $this->assertNotEmpty($thenCallbacks);

        $thenCallback = $thenCallbacks[0];
        if (is_object($thenCallback) && method_exists($thenCallback, 'getClosure')) {
            $thenCallback = $thenCallback->getClosure();
        }

        $fakeBatch = Bus::dispatchFakeBatch('media-upload-livestream-chain-test');
        $thenCallback($fakeBatch);

        Bus::assertChained([
            AnalyzeSegments::class,
            TranscribeFullService::class,
            DetectServiceStructure::class,
            ProjectLivestreamServiceStructure::class,
            MatchSongsFromTranscript::class,
            MergeSongContinuations::class,
            ProjectLivestreamServiceStructure::class,
            ExtractSermon::class,
            SubmitToProcessing::class,
            EnhanceAudio::class,
            IdentifySpeaker::class,
            CreateSermonTranscriptFromService::class,
            ProcessTranscriptWithAI::class,
            AssessSermonVideoQuality::class,
            GenerateThumbnail::class,
            PrepareSectionPublicationCandidates::class,
            SendCompletionNotification::class,
            PromoteHistoricAssets::class,
            CleanupTemporaryFiles::class,
        ]);
    }
}
