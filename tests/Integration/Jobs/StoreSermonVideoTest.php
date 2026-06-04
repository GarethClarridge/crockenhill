<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Jobs\StoreSermonVideo;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Processing\SermonMetadataIntegrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StoreSermonVideoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_has_correct_retry_configuration(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->make();
        $sermon = Sermon::factory()->make();
        $job = new StoreSermonVideo($log, $sermon->id ?? 1);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals(1800, $job->timeout);
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
        $log = MediaProcessingLog::factory()->livestream()->processing()->create();

        Log::shouldReceive('error')->once()->withArgs(fn (string $message) => str_contains($message, 'StoreSermonVideo'));

        $job = new StoreSermonVideo($log, 1);
        $job->failed(new \Exception('S3 upload timed out'));

        $log->refresh();

        $this->assertSame('failed', $log->status->value);
        $this->assertSame('sermon_creation', $log->current_step);
        $this->assertStringContainsString('Sermon video upload failed after 3 attempts', (string) $log->error_message);
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
