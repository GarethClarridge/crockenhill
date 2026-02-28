<?php

namespace Tests\Unit\Jobs;

use App\Jobs\PrepareSectionPublicationCandidates;
use App\Jobs\SubmitToProcessing;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\SermonCreationService;
use App\Services\SermonMetadataIntegrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubmitToProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    #[Test]
    public function it_has_correct_retry_configuration(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->make();
        $job = new SubmitToProcessing($log);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals(1800, $job->timeout);
    }

    #[Test]
    public function it_throws_when_audio_file_path_is_missing(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'audio_file_path' => null,
        ]);

        $mockMetadataService = $this->createMock(SermonMetadataIntegrationService::class);
        $mockCreationService = $this->createMock(SermonCreationService::class);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->atLeast()->once();

        $job = new SubmitToProcessing($log);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Sermon audio path not found in processing log');

        $job->handle($mockMetadataService, $mockCreationService);
    }

    #[Test]
    public function it_throws_when_audio_file_not_found_on_disk(): void
    {
        Storage::fake('public');

        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'audio_file_path' => 'sermons/audio/missing-file.mp3',
        ]);

        $mockMetadataService = $this->createMock(SermonMetadataIntegrationService::class);
        $mockCreationService = $this->createMock(SermonCreationService::class);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        config(['media-processing.storage.sermon_disk' => 'public']);

        $job = new SubmitToProcessing($log);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Sermon audio file not found/');

        $job->handle($mockMetadataService, $mockCreationService);
    }

    #[Test]
    public function it_creates_sermon_record_and_stores_video(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('sermons/audio/test-audio.mp3', 'fake-audio-content');

        config(['media-processing.storage.sermon_disk' => 'public']);

        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'audio_file_path' => 'sermons/audio/test-audio.mp3',
            'original_filename' => '2026-01-15-livestream.mp4',
            'video_file_path' => 'temp/video.mp4',
        ]);

        $createdSermon = Sermon::factory()->create();

        $mockMetadataService = $this->createMock(SermonMetadataIntegrationService::class);
        $mockMetadataService->expects($this->once())
            ->method('storeVideoForSermon')
            ->with($log->processing_id, $createdSermon->id)
            ->willReturn('sermons/'.$createdSermon->id.'/video.mp4');
        $mockMetadataService->expects($this->once())
            ->method('linkVideoToSermon');

        $mockCreationService = $this->createMock(SermonCreationService::class);
        $mockCreationService->expects($this->once())
            ->method('createSermon')
            ->willReturn($createdSermon);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $job = new SubmitToProcessing($log);
        $job->handle($mockMetadataService, $mockCreationService);

        $log->refresh();
        $this->assertEquals($createdSermon->id, $log->sermon_id);
        $this->assertEquals('transcription', $log->current_step);
        Queue::assertPushed(PrepareSectionPublicationCandidates::class);
    }

    #[Test]
    public function failed_method_marks_processing_log_as_failed(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create();

        Log::shouldReceive('error')->atLeast()->once();

        $job = new SubmitToProcessing($log);
        $job->failed(new \Exception('Permanent creation failure'));

        $log->refresh();
        $this->assertEquals('failed', $log->status->value);
    }
}
