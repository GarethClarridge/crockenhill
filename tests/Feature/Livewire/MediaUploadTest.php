<?php

namespace Tests\Feature\Livewire;

use App\Jobs\AnalyzeSegments;
use App\Jobs\ClassifyServiceSections;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\ExtractSermon;
use App\Jobs\GenerateThumbnail;
use App\Jobs\IdentifySpeaker;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\SendCompletionNotification;
use App\Jobs\SubmitToProcessing;
use App\Jobs\TranscribeAudio;
use App\Livewire\MediaUpload;
use App\Models\MediaProcessingLog;
use App\Models\User;
use App\Services\UnifiedMediaProcessor;
use App\Services\VideoSegmentationService;
use App\Services\VideoStorageService;
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

        $this->admin = User::factory()->crockenhillAdmin()->create(['is_admin' => true]);

        // Fake disks used by the component
        Storage::fake('local');
    }

    #[Test]
    public function it_renders_successfully()
    {
        $this->actingAs($this->admin);

        Livewire::test(MediaUpload::class)
            ->assertStatus(200)
            ->assertSee('Upload Media');
    }

    #[Test]
    public function it_requires_authentication_and_admin_permissions()
    {
        // Unauthenticated
        $this->get(route('admin.sermon-upload.create'))
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
            ->assertSet('status', 'failed')
            ->assertSet('errorMessage', 'File upload completed but file is missing');
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
            ->assertHasErrors(['mediaFile']);
    }

    #[Test]
    public function it_starts_processing_after_successful_upload()
    {
        $this->actingAs($this->admin);

        $expectedId = '00000000-0000-0000-0000-000000000000';

        Storage::fake('local');
        $file = UploadedFile::fake()->create('sermon.mp3', 1024);

        $mockResult = \App\Services\ProcessingResult::success($expectedId, 'Started');

        $mockProcessor = $this->createMock(UnifiedMediaProcessor::class);
        $mockProcessor->expects($this->once())
            ->method('process')
            ->willReturn($mockResult);

        $this->app->instance(UnifiedMediaProcessor::class, $mockProcessor);

        $test = Livewire::test(MediaUpload::class)
            ->set('mediaType', 'audio')
            ->set('mediaFile', $file)
            ->call('uploadComplete');

        if ($test->get('status') === 'failed') {
            $this->fail($test->get('errorMessage'));
        }

        $test->assertSet('status', 'processing')
            ->assertSet('processingId', $expectedId)
            ->assertSet('successMessage', 'Upload complete. Processing has started.');
    }

    #[Test]
    public function it_handles_processing_failures_gracefully()
    {
        $this->actingAs($this->admin);

        $file = UploadedFile::fake()->create('sermon.mp3', 1024);

        $mockResult = \App\Services\ProcessingResult::failure('test-proc-id', 'System error', 'ERR_CODE');

        $mockProcessor = $this->createMock(UnifiedMediaProcessor::class);
        $mockProcessor->expects($this->once())
            ->method('process')
            ->willReturn($mockResult);

        $this->app->instance(UnifiedMediaProcessor::class, $mockProcessor);

        Livewire::test(MediaUpload::class)
            ->set('mediaType', 'audio')
            ->set('mediaFile', $file)
            ->call('uploadComplete')
            ->assertSet('status', 'failed')
            ->assertSet('errorMessage', 'System error');
    }

    #[Test]
    public function it_ignores_duplicate_upload_complete_triggers_for_the_same_file(): void
    {
        $this->actingAs($this->admin);

        $expectedId = '00000000-0000-0000-0000-000000000123';
        $file = UploadedFile::fake()->create('sermon.mp3', 1024);

        $mockResult = \App\Services\ProcessingResult::success($expectedId, 'Started');

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
            ->assertSet('status', 'processing');
    }

    #[Test]
    public function it_can_cancel_upload()
    {
        $this->actingAs($this->admin);

        Livewire::test(MediaUpload::class)
            ->set('isUploading', true)
            ->call('cancelUpload')
            ->assertSet('isUploading', false)
            ->assertSet('uploadCancelled', true)
            ->assertSet('status', 'idle');
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
            ->set('status', 'processing')
            ->call('cancelProcessing')
            ->assertSet('status', 'cancelled')
            ->assertSet('currentStep', 'Processing cancelled')
            ->assertSet('errorMessage', null)
            ->assertSet('cancelledMessage', 'Processing was cancelled by user.');
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
            ->set('status', 'processing')
            ->call('checkProcessingStatus')
            ->assertSet('status', 'cancelled')
            ->assertSet('currentStep', 'Processing cancelled')
            ->assertSet('progressPercentage', 0)
            ->assertSet('errorMessage', null)
            ->assertSet('cancelledMessage', 'Processing was cancelled.');
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

        $mockSegmentationService = $this->createMock(VideoSegmentationService::class);
        $mockSegmentationService->method('validateVideoFile')->willReturn(true);
        $mockSegmentationService->method('getVideoMetadata')->willReturn([
            'duration' => 3600.0,
            'format' => 'mp4',
            'size' => 50000,
        ]);

        $mockStorageService = $this->createMock(VideoStorageService::class);
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
            ->assertSet('status', 'processing')
            ->assertSet('errorMessage', null);

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
            ClassifyServiceSections::class,
            ExtractSermon::class,
            SubmitToProcessing::class,
            IdentifySpeaker::class,
            TranscribeAudio::class,
            ProcessTranscriptWithAI::class,
            GenerateThumbnail::class,
            SendCompletionNotification::class,
            CleanupTemporaryFiles::class,
        ]);
    }
}
