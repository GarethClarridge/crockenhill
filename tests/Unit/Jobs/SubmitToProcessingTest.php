<?php

namespace Tests\Unit\Jobs;

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
        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_reuses_an_existing_sermon_record_when_refreshing_livestream_outputs(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('sermons/audio/refreshed-audio.mp3', 'fake-audio-content');

        config(['media-processing.storage.sermon_disk' => 'public']);

        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'sermons/audio/original-audio.mp3',
            'livestream_processing_id' => null,
        ]);

        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'sermon_id' => $sermon->id,
            'audio_file_path' => 'sermons/audio/refreshed-audio.mp3',
            'original_filename' => '2026-01-15-livestream.mp4',
            'video_file_path' => 'temp/video.mp4',
            'sermon_start_time' => 300.0,
            'sermon_end_time' => 2100.0,
        ]);

        $mockMetadataService = $this->createMock(SermonMetadataIntegrationService::class);
        $mockMetadataService->expects($this->once())
            ->method('storeVideoForSermon')
            ->with($log->processing_id, $sermon->id)
            ->willReturn('sermons/'.$sermon->id.'/video.mp4');
        $mockMetadataService->expects($this->once())
            ->method('linkVideoToSermon')
            ->with($log->processing_id, $sermon->id, 'sermons/'.$sermon->id.'/video.mp4');

        $mockCreationService = $this->createMock(SermonCreationService::class);
        $mockCreationService->expects($this->never())->method('createSermon');

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $job = new SubmitToProcessing($log);
        $job->handle($mockMetadataService, $mockCreationService);

        $log->refresh();
        $sermon->refresh();

        $this->assertSame($sermon->id, $log->sermon_id);
        $this->assertSame('sermons/audio/refreshed-audio.mp3', $sermon->audio_file_path);
        $this->assertSame($log->processing_id, $sermon->livestream_processing_id);
        $this->assertSame('transcription', $log->current_step);
        $this->assertSame(1, Sermon::query()->where('livestream_processing_id', $log->processing_id)->count());
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

    #[Test]
    public function it_skips_all_work_when_processing_is_cancelled(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->cancelled()->create();

        $mockMetadataService = $this->createMock(SermonMetadataIntegrationService::class);
        $mockMetadataService->expects($this->never())->method('storeVideoForSermon');

        $mockCreationService = $this->createMock(SermonCreationService::class);
        $mockCreationService->expects($this->never())->method('createSermon');

        Log::shouldReceive('info')->once()->with('SubmitToProcessing job skipped: processing cancelled', \Mockery::any());

        $job = new SubmitToProcessing($log);
        $job->handle($mockMetadataService, $mockCreationService);
    }

    #[Test]
    public function mark_as_failed_does_not_overwrite_a_cancelled_run(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->cancelled()->create();

        $result = app(\App\Services\MediaProcessingRunTransitionService::class)
            ->markAsFailed($log, 'Sermon creation from livestream failed: something went wrong');

        $this->assertFalse($result);
        $log->refresh();
        $this->assertEquals('cancelled', $log->status->value, 'markAsFailed must not overwrite a cancelled run');
    }

    #[Test]
    public function cancelled_run_is_not_overwritten_to_failed_by_failed_method(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->cancelled()->create();

        Log::shouldReceive('error')->atLeast()->once();

        $job = new SubmitToProcessing($log);
        $job->failed(new \Exception('Queue exhausted retries'));

        $log->refresh();
        $this->assertEquals('cancelled', $log->status->value, 'Cancelled run must not be overwritten to failed by failed()');
    }

    #[Test]
    public function it_reuses_the_existing_run_owned_sermon_when_the_log_is_in_a_mixed_state(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('sermons/audio/mixed-state.mp3', 'fake-audio-content');

        config(['media-processing.storage.sermon_disk' => 'public']);

        $log = MediaProcessingLog::factory()->livestream()->failed()->create([
            'processing_id' => 'mixed-state-processing',
            'sermon_id' => null,
            'audio_file_path' => 'sermons/audio/mixed-state.mp3',
            'video_file_path' => 'temp/video.mp4',
            'sermon_start_time' => 300.0,
            'sermon_end_time' => 2100.0,
            'current_step' => 'sermon_creation',
        ]);

        $sermon = Sermon::factory()->fromLivestream()->create([
            'audio_file_path' => 'sermons/audio/original.mp3',
            'livestream_processing_id' => $log->processing_id,
        ]);

        $mockMetadataService = $this->createMock(SermonMetadataIntegrationService::class);
        $mockMetadataService->expects($this->once())
            ->method('storeVideoForSermon')
            ->with($log->processing_id, $sermon->id)
            ->willReturn('sermons/'.$sermon->id.'/video.mp4');
        $mockMetadataService->expects($this->once())
            ->method('linkVideoToSermon')
            ->with($log->processing_id, $sermon->id, 'sermons/'.$sermon->id.'/video.mp4');

        $mockCreationService = $this->createMock(SermonCreationService::class);
        $mockCreationService->expects($this->never())->method('createSermon');

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $job = new SubmitToProcessing($log);
        $job->handle($mockMetadataService, $mockCreationService);

        $log->refresh();
        $sermon->refresh();

        $this->assertSame($sermon->id, $log->sermon_id);
        $this->assertSame('sermons/audio/mixed-state.mp3', $sermon->audio_file_path);
        $this->assertSame($log->processing_id, $sermon->livestream_processing_id);
        $this->assertSame(1, Sermon::query()->where('livestream_processing_id', $log->processing_id)->count());
    }
}
