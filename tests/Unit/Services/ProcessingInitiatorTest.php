<?php

namespace Tests\Unit\Services;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Models\MediaProcessingLog;
use App\Models\User;
use App\Services\MetadataExtractionService;
use App\Services\ProcessingInitiator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessingInitiatorTest extends TestCase
{
    use RefreshDatabase;

    private ProcessingInitiator $initiator;

    private MetadataExtractionService $metadataService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->metadataService = $this->createMock(MetadataExtractionService::class);
        $this->initiator = new ProcessingInitiator($this->metadataService);
    }

    #[Test]
    public function it_creates_processing_log_with_pending_status(): void
    {
        $file = UploadedFile::fake()->create('sermon.mp4', 2048);
        $extractedDateTime = Carbon::create(2026, 2, 10, 10, 30, 0);

        $this->metadataService->method('extractDateFromVideo')->willReturn($extractedDateTime);
        $this->metadataService->method('determineServiceFromTime')->willReturn(SermonService::MORNING);

        $log = $this->initiator->initiateProcessing($file, MediaType::Video);

        $this->assertInstanceOf(MediaProcessingLog::class, $log);
        $this->assertEquals(ProcessingStatus::PENDING, $log->status);
        $this->assertEquals(MediaType::Video, $log->processing_type);
        $this->assertEquals('sermon.mp4', $log->original_filename);
        $this->assertNotEmpty($log->processing_id);
    }

    #[Test]
    public function it_extracts_metadata_and_stores_in_processing_log(): void
    {
        $file = UploadedFile::fake()->create('2026-02-10.mp4', 2048);
        $extractedDateTime = Carbon::create(2026, 2, 10, 18, 30, 0);

        $this->metadataService->method('extractDateFromVideo')->willReturn($extractedDateTime);
        $this->metadataService->method('determineServiceFromTime')->willReturn(SermonService::EVENING);

        $log = $this->initiator->initiateProcessing($file, MediaType::Video);

        $this->assertEquals('2026-02-10', $log->processing_metadata['extracted_date']);
        $this->assertEquals('evening', $log->processing_metadata['extracted_service']);
        $this->assertEquals('video_metadata_or_filename', $log->processing_metadata['date_extraction_method']);
    }

    #[Test]
    public function it_uses_time_based_service_detection_when_time_is_not_midnight(): void
    {
        $file = UploadedFile::fake()->create('sermon.mp4', 2048);
        $extractedDateTime = Carbon::create(2026, 1, 15, 10, 30, 0);

        $this->metadataService->method('extractDateFromVideo')->willReturn($extractedDateTime);
        $this->metadataService
            ->expects($this->once())
            ->method('determineServiceFromTime')
            ->with($extractedDateTime)
            ->willReturn(SermonService::MORNING);

        $this->initiator->initiateProcessing($file, MediaType::Video);
    }

    #[Test]
    public function it_falls_back_to_filename_detection_when_time_is_midnight(): void
    {
        $file = UploadedFile::fake()->create('evening-sermon.mp4', 2048);
        $extractedDateTime = Carbon::create(2026, 1, 15, 0, 0, 0);

        $this->metadataService->method('extractDateFromVideo')->willReturn($extractedDateTime);
        $this->metadataService
            ->expects($this->once())
            ->method('determineServiceFromFilename')
            ->with('evening-sermon.mp4')
            ->willReturn(SermonService::EVENING);

        $log = $this->initiator->initiateProcessing($file, MediaType::Video);

        $this->assertEquals('evening', $log->processing_metadata['extracted_service']);
    }

    #[Test]
    public function it_passes_client_file_date_to_metadata_service(): void
    {
        $file = UploadedFile::fake()->create('sermon.mp4', 2048);
        $clientFileDate = '2026-02-01';
        $extractedDateTime = Carbon::create(2026, 2, 1, 10, 0, 0);

        $this->metadataService
            ->expects($this->once())
            ->method('extractDateFromVideo')
            ->with($file, $clientFileDate)
            ->willReturn($extractedDateTime);
        $this->metadataService->method('determineServiceFromTime')->willReturn(SermonService::MORNING);

        $this->initiator->initiateProcessing($file, MediaType::Video, $clientFileDate);
    }

    #[Test]
    public function it_sets_correct_processing_type_for_livestream(): void
    {
        $file = UploadedFile::fake()->create('livestream.mp4', 5120);
        $extractedDateTime = Carbon::create(2026, 2, 10, 10, 30, 0);

        $this->metadataService->method('extractDateFromVideo')->willReturn($extractedDateTime);
        $this->metadataService->method('determineServiceFromTime')->willReturn(SermonService::MORNING);

        $log = $this->initiator->initiateProcessing($file, MediaType::Livestream);

        $this->assertEquals(MediaType::Livestream, $log->processing_type);
        $this->assertEquals('livestream_processing_initiated', $log->current_step);
    }

    #[Test]
    public function it_merges_additional_log_data(): void
    {
        $file = UploadedFile::fake()->create('sermon.mp4', 2048);
        $extractedDateTime = Carbon::create(2026, 2, 10, 10, 30, 0);

        $this->metadataService->method('extractDateFromVideo')->willReturn($extractedDateTime);
        $this->metadataService->method('determineServiceFromTime')->willReturn(SermonService::MORNING);

        $log = $this->initiator->initiateProcessing($file, MediaType::Livestream, null, [
            'source_file_path' => 'livestream/temp/test.mp4',
            'file_size' => 50000000,
            'duration' => 3600.0,
        ]);

        $this->assertEquals('livestream/temp/test.mp4', $log->source_file_path);
        $this->assertEquals(50000000, $log->file_size);
        $this->assertEquals(3600.0, $log->duration);
    }

    #[Test]
    public function it_merges_additional_processing_metadata(): void
    {
        $file = UploadedFile::fake()->create('video.mp4', 5120);
        $extractedDateTime = Carbon::create(2026, 2, 10, 10, 30, 0);

        $this->metadataService->method('extractDateFromVideo')->willReturn($extractedDateTime);
        $this->metadataService->method('determineServiceFromTime')->willReturn(SermonService::MORNING);

        $log = $this->initiator->initiateProcessing($file, MediaType::Livestream, null, [
            'processing_metadata' => [
                'upload_time' => '2026-02-10T10:30:00Z',
                'mime_type' => 'video/mp4',
                'format_details' => ['format_name' => 'mp4'],
            ],
        ]);

        // Core metadata should be present
        $this->assertEquals('2026-02-10', $log->processing_metadata['extracted_date']);
        $this->assertEquals('morning', $log->processing_metadata['extracted_service']);

        // Additional metadata should be merged
        $this->assertEquals('video/mp4', $log->processing_metadata['mime_type']);
        $this->assertArrayHasKey('format_details', $log->processing_metadata);
    }

    #[Test]
    public function it_generates_unique_processing_ids(): void
    {
        $file = UploadedFile::fake()->create('sermon.mp4', 2048);
        $extractedDateTime = Carbon::create(2026, 2, 10, 10, 30, 0);

        $this->metadataService->method('extractDateFromVideo')->willReturn($extractedDateTime);
        $this->metadataService->method('determineServiceFromTime')->willReturn(SermonService::MORNING);

        $log1 = $this->initiator->initiateProcessing($file, MediaType::Video);
        $log2 = $this->initiator->initiateProcessing($file, MediaType::Video);

        $this->assertNotEquals($log1->processing_id, $log2->processing_id);
    }

    #[Test]
    public function it_persists_the_log_to_database(): void
    {
        $file = UploadedFile::fake()->create('sermon.mp4', 2048);
        $extractedDateTime = Carbon::create(2026, 2, 10, 10, 30, 0);

        $this->metadataService->method('extractDateFromVideo')->willReturn($extractedDateTime);
        $this->metadataService->method('determineServiceFromTime')->willReturn(SermonService::MORNING);

        $log = $this->initiator->initiateProcessing($file, MediaType::Video);

        $this->assertDatabaseHas('media_processing_logs', [
            'processing_id' => $log->processing_id,
            'processing_type' => 'video',
            'status' => ProcessingStatus::PENDING->value,
        ]);
    }

    #[Test]
    public function it_sets_owner_user_id_when_authenticated(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $file = UploadedFile::fake()->create('sermon.mp4', 2048);
        $extractedDateTime = Carbon::create(2026, 2, 10, 10, 30, 0);

        $this->metadataService->method('extractDateFromVideo')->willReturn($extractedDateTime);
        $this->metadataService->method('determineServiceFromTime')->willReturn(SermonService::MORNING);

        $log = $this->initiator->initiateProcessing($file, MediaType::Video);

        $this->assertEquals($user->id, $log->owner_user_id);
    }
}
