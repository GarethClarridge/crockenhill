<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Jobs\StoreSermonVideo;
use App\Models\HistoricImportNestedJob;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Processing\SermonMetadataIntegrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class StoreSermonVideoTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    #[Test]
    public function it_has_correct_retry_configuration(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->make();
        $sermon = Sermon::factory()->make();
        $job = new StoreSermonVideo($log, $sermon->id ?? 1);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals(1800, $job->timeout);
        $this->assertSame([60, 300], $job->backoff());
    }

    #[Test]
    public function it_calls_store_and_link_video_for_a_valid_log(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'video_file_path' => 'temp/sermon.mp4',
        ]);
        $sermon = Sermon::factory()->create();

        $mockMetadataService = $this->createMock(SermonMetadataIntegrationService::class);
        $mockMetadataService->expects($this->once())
            ->method('storeVideoForSermon')
            ->with($log->processing_id, $sermon->id)
            ->willReturn("sermons/{$sermon->id}/video.mp4");
        $mockMetadataService->expects($this->once())
            ->method('linkVideoToSermon')
            ->with($log->processing_id, $sermon->id, "sermons/{$sermon->id}/video.mp4");

        Log::shouldReceive('info')->atLeast()->once();

        $job = new StoreSermonVideo($log, $sermon->id);
        $job->handle($mockMetadataService);
    }

    #[Test]
    public function historic_storage_is_operation_owned_and_marked_complete_after_linking(): void
    {
        $operation = $this->createHistoricImportOperation();
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'historic_import_operation_id' => $operation->id,
            'video_file_path' => 'temp/sermon.mp4',
        ]);
        $sermon = Sermon::factory()->create();
        $nested = HistoricImportNestedJob::query()->create([
            'historic_import_operation_id' => $operation->id,
            'media_processing_log_id' => $log->id,
            'job_key' => StoreSermonVideo::nestedJobKey($log->processing_id),
            'job_type' => StoreSermonVideo::class,
            'state' => 'queued',
            'attempts' => 0,
            'dispatched_at' => now(),
        ]);

        $mockMetadataService = $this->createMock(SermonMetadataIntegrationService::class);
        $mockMetadataService->expects($this->once())
            ->method('storeVideoForSermon')
            ->with($log->processing_id, $sermon->id)
            ->willReturn("sermons/{$sermon->id}/video.mp4");
        $mockMetadataService->expects($this->once())
            ->method('linkVideoToSermon')
            ->with($log->processing_id, $sermon->id, "sermons/{$sermon->id}/video.mp4");

        Log::shouldReceive('info')->atLeast()->once();

        (new StoreSermonVideo($log, $sermon->id))->handle($mockMetadataService);

        $nested->refresh();
        $this->assertSame($operation->id, $nested->historic_import_operation_id);
        $this->assertSame($log->id, $nested->media_processing_log_id);
        $this->assertSame('completed', $nested->state);
        $this->assertSame(1, $nested->attempts);
        $this->assertNotNull($nested->settled_at);
    }

    #[Test]
    public function retryable_storage_is_recorded_before_the_exception_is_rethrown(): void
    {
        $operation = $this->createHistoricImportOperation();
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'historic_import_operation_id' => $operation->id,
            'video_file_path' => 'temp/sermon.mp4',
        ]);
        $sermon = Sermon::factory()->create();
        $nested = HistoricImportNestedJob::query()->create([
            'historic_import_operation_id' => $operation->id,
            'media_processing_log_id' => $log->id,
            'job_key' => StoreSermonVideo::nestedJobKey($log->processing_id),
            'job_type' => StoreSermonVideo::class,
            'state' => 'queued',
            'attempts' => 0,
            'dispatched_at' => now(),
        ]);

        $mockMetadataService = $this->createMock(SermonMetadataIntegrationService::class);
        $mockMetadataService->expects($this->once())
            ->method('storeVideoForSermon')
            ->willThrowException(new \RuntimeException('S3 upload timed out'));

        Log::shouldReceive('info')->atLeast()->once();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('S3 upload timed out');

        try {
            (new StoreSermonVideo($log, $sermon->id))->handle($mockMetadataService);
        } finally {
            $nested->refresh();
            $this->assertSame('retryable', $nested->state);
            $this->assertSame(1, $nested->attempts);
            $this->assertSame(
                hash('sha256', \RuntimeException::class."\0S3 upload timed out"),
                $nested->error_fingerprint,
            );
        }
    }

    #[Test]
    public function it_skips_all_work_when_processing_is_cancelled(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->cancelled()->create();
        $sermon = Sermon::factory()->create();

        $mockMetadataService = $this->createMock(SermonMetadataIntegrationService::class);
        $mockMetadataService->expects($this->never())->method('storeVideoForSermon');
        $mockMetadataService->expects($this->never())->method('linkVideoToSermon');

        Log::shouldReceive('info')->once()->withArgs(fn (string $message) => str_contains($message, 'cancelled'));

        $job = new StoreSermonVideo($log, $sermon->id);
        $job->handle($mockMetadataService);
    }

    #[Test]
    public function it_returns_early_when_processing_log_no_longer_exists(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->make(['id' => 99999]);

        $mockMetadataService = $this->createMock(SermonMetadataIntegrationService::class);
        $mockMetadataService->expects($this->never())->method('storeVideoForSermon');

        Log::shouldReceive('warning')->atLeast()->once();

        $job = new StoreSermonVideo($log, 1);
        $job->handle($mockMetadataService);
    }

    #[Test]
    public function failed_method_logs_permanent_failure(): void
    {
        $operation = $this->createHistoricImportOperation();
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'historic_import_operation_id' => $operation->id,
        ]);
        $nested = HistoricImportNestedJob::query()->create([
            'historic_import_operation_id' => $operation->id,
            'media_processing_log_id' => $log->id,
            'job_key' => StoreSermonVideo::nestedJobKey($log->processing_id),
            'job_type' => StoreSermonVideo::class,
            'state' => 'retryable',
            'attempts' => 3,
            'dispatched_at' => now(),
        ]);

        Log::shouldReceive('error')->once()->withArgs(fn (string $message) => str_contains($message, 'StoreSermonVideo'));

        $job = new StoreSermonVideo($log, 1);
        $job->failed(new \Exception('S3 upload timed out'));

        $log->refresh();
        $nested->refresh();

        $this->assertSame('failed', $log->status->value);
        $this->assertSame('sermon_submitted', $log->current_step);
        $this->assertStringContainsString('Sermon video upload failed after 3 attempts', (string) $log->error_message);
        $this->assertSame('failed', $nested->state);
        $this->assertNotNull($nested->settled_at);
    }

    #[Test]
    public function failed_method_does_not_overwrite_a_cancelled_run(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->cancelled()->create();

        Log::shouldReceive('error')->once()->withArgs(fn (string $message) => str_contains($message, 'StoreSermonVideo'));

        $job = new StoreSermonVideo($log, 1);
        $job->failed(new \Exception('S3 upload timed out'));

        $log->refresh();

        $this->assertSame('cancelled', $log->status->value);
    }
}
