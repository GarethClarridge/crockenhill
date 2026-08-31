<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Data\SermonCreationOptions;
use App\Jobs\StoreSermonVideo;
use App\Jobs\SubmitToProcessing;
use App\Models\HistoricImportNestedJob;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Processing\MediaProcessingRunTransitionService;
use App\Services\Sermon\SermonCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class SubmitToProcessingTest extends TestCase
{
    use CreatesHistoricImportOperations;
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

        $mockCreationService = $this->createMock(SermonCreationService::class);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->atLeast()->once();

        $job = new SubmitToProcessing($log);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Sermon audio path not found in processing log');

        $job->handle($mockCreationService);
    }

    #[Test]
    public function it_throws_when_audio_file_not_found_on_disk(): void
    {
        Storage::fake('public');

        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'audio_file_path' => 'sermons/audio/missing-file.mp3',
        ]);

        $mockCreationService = $this->createMock(SermonCreationService::class);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        config(['media-processing.storage.sermon_disk' => 'public']);

        $job = new SubmitToProcessing($log);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Sermon audio file not found/');

        $job->handle($mockCreationService);
    }

    #[Test]
    public function it_creates_sermon_with_observed_duration_for_a_concat_extraction(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('sermons/audio/test-audio.mp3', 'fake-audio-content');

        config(['media-processing.storage.sermon_disk' => 'public']);

        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'audio_file_path' => 'sermons/audio/test-audio.mp3',
            'original_filename' => '2026-01-15-livestream.mp4',
            'video_file_path' => 'temp/video.mp4',
            'sermon_start_time' => 120.0,
            'sermon_end_time' => 2100.0,
            'processing_metadata' => [
                'sermon_extraction_plan' => [
                    'mode' => 'concat_spans',
                    'segments' => [
                        ['start_time' => 120.0, 'end_time' => 300.0],
                        ['start_time' => 900.0, 'end_time' => 2100.0],
                    ],
                ],
                'trim' => [
                    'final_duration' => 1380.0,
                    'observed_duration' => 1325.25,
                ],
            ],
        ]);

        $createdSermon = Sermon::factory()->create();

        $mockCreationService = $this->createMock(SermonCreationService::class);
        $mockCreationService->expects($this->once())
            ->method('createSermon')
            ->with(
                $this->isInstanceOf(MediaProcessingLog::class),
                $this->callback(function (SermonCreationOptions $options): bool {
                    return $options->duration === 1325.25
                        && $options->resolvedDuration() === 1325.25;
                }),
            )
            ->willReturn($createdSermon);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $job = new SubmitToProcessing($log);
        $job->handle($mockCreationService);

        $log->refresh();
        $this->assertEquals($createdSermon->id, $log->sermon_id);
        $this->assertEquals('transcription', $log->current_step);

        Queue::assertPushed(StoreSermonVideo::class, function (StoreSermonVideo $job) use ($createdSermon): bool {
            return $job->sermonId === $createdSermon->id;
        });
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

        $mockCreationService = $this->createMock(SermonCreationService::class);
        $mockCreationService->expects($this->never())->method('createSermon');

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $job = new SubmitToProcessing($log);
        $job->handle($mockCreationService);

        $log->refresh();
        $sermon->refresh();

        $this->assertSame($sermon->id, $log->sermon_id);
        $this->assertSame('sermons/audio/refreshed-audio.mp3', $sermon->audio_file_path);
        $this->assertSame($log->processing_id, $sermon->livestream_processing_id);
        $this->assertSame('transcription', $log->current_step);
        $this->assertSame(1, Sermon::query()->where('livestream_processing_id', $log->processing_id)->count());

        Queue::assertPushed(StoreSermonVideo::class);
    }

    #[Test]
    public function it_refreshes_existing_sermon_duration_from_observed_concat_media(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('sermons/audio/refreshed-duration.mp3', 'fake-audio-content');

        config(['media-processing.storage.sermon_disk' => 'public']);

        $sermon = Sermon::factory()->fromLivestream()->create([
            'audio_file_path' => 'sermons/audio/original-duration.mp3',
            'duration' => 3600.0,
            'title' => 'Curated sermon title',
            'reference' => 'John 3:16',
            'series' => 'Curated series',
            'preacher' => 'Curated preacher',
            'livestream_processing_id' => null,
        ]);

        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'sermon_id' => $sermon->id,
            'audio_file_path' => 'sermons/audio/refreshed-duration.mp3',
            'original_filename' => '2026-01-15-livestream.mp4',
            'video_file_path' => 'temp/video.mp4',
            'sermon_start_time' => 300.0,
            'sermon_end_time' => 2100.0,
            'processing_metadata' => [
                'sermon_extraction_plan' => [
                    'segments' => [
                        ['start_time' => 300.0, 'end_time' => 1200.0],
                        ['start_time' => 1500.0, 'end_time' => 2100.0],
                    ],
                ],
                'trim' => [
                    'final_duration' => 1500.0,
                    'observed_duration' => 1425.5,
                ],
            ],
        ]);

        $mockCreationService = $this->createMock(SermonCreationService::class);
        $mockCreationService->expects($this->never())->method('createSermon');

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $job = new SubmitToProcessing($log);
        $job->handle($mockCreationService);

        $sermon->refresh();

        $this->assertEquals(1425.5, $sermon->duration);
        $this->assertSame('Curated sermon title', $sermon->title);
        $this->assertSame('John 3:16', $sermon->reference);
        $this->assertSame('Curated series', $sermon->series);
        $this->assertSame('Curated preacher', $sermon->preacher);
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

        $mockCreationService = $this->createMock(SermonCreationService::class);
        $mockCreationService->expects($this->never())->method('createSermon');

        Log::shouldReceive('info')->once()->withArgs(fn ($msg) => str_contains($msg, 'skipped') && str_contains($msg, 'cancelled'));

        $job = new SubmitToProcessing($log);
        $job->handle($mockCreationService);

        Queue::assertNotPushed(StoreSermonVideo::class);
    }

    #[Test]
    public function mark_as_failed_does_not_overwrite_a_cancelled_run(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->cancelled()->create();

        $result = app(MediaProcessingRunTransitionService::class)
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

        $mockCreationService = $this->createMock(SermonCreationService::class);
        $mockCreationService->expects($this->never())->method('createSermon');

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $job = new SubmitToProcessing($log);
        $job->handle($mockCreationService);

        $log->refresh();
        $sermon->refresh();

        $this->assertSame($sermon->id, $log->sermon_id);
        $this->assertSame('sermons/audio/mixed-state.mp3', $sermon->audio_file_path);
        $this->assertSame($log->processing_id, $sermon->livestream_processing_id);
        $this->assertSame(1, Sermon::query()->where('livestream_processing_id', $log->processing_id)->count());

        Queue::assertPushed(StoreSermonVideo::class);
    }

    #[Test]
    public function it_registers_historic_video_storage_as_operation_owned_nested_work(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('sermons/audio/historic-audio.mp3', 'fake-audio-content');

        config(['media-processing.storage.sermon_disk' => 'public']);

        $operation = $this->createHistoricImportOperation();
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'historic_import_operation_id' => $operation->id,
            'audio_file_path' => 'sermons/audio/historic-audio.mp3',
            'video_file_path' => 'temp/historic-video.mp4',
        ]);
        $sermon = Sermon::factory()->create();

        $mockCreationService = $this->createMock(SermonCreationService::class);
        $mockCreationService->expects($this->once())
            ->method('createSermon')
            ->willReturn($sermon);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        (new SubmitToProcessing($log))->handle($mockCreationService);

        $nested = HistoricImportNestedJob::query()->sole();
        $this->assertSame($operation->id, $nested->historic_import_operation_id);
        $this->assertSame($log->id, $nested->media_processing_log_id);
        $this->assertSame(StoreSermonVideo::class, $nested->job_type);
        $this->assertSame(StoreSermonVideo::nestedJobKey($log->processing_id), $nested->job_key);
        $this->assertSame('queued', $nested->state);
        $this->assertSame(0, $nested->attempts);
        $this->assertNotNull($nested->dispatched_at);
        Queue::assertPushed(StoreSermonVideo::class);
    }
}
