<?php

declare(strict_types=1);

namespace Tests\Feature\MediaProcessing;

use App\Enums\ProcessingStatus;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\SendCompletionNotification;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Media\Video\VideoStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompletionOutcomePreservationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_preserves_notification_failed_permanently_step_through_cleanup(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Sermon With Permanently Failed Notification',
            'slug' => 'sermon-permanent-fail',
        ]);

        // Simulate what SendCompletionNotification::failed() writes after all retries are exhausted
        $processingLog = MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => $sermon->id,
            'source_file_path' => 'temp/sermon-permanent-fail.mp3',
            'current_step' => 'notification_failed_permanently',
            'error_message' => 'Notification failed permanently: Connection timed out',
        ]);

        $storageService = $this->createMock(VideoStorageService::class);
        $storageService->expects($this->once())
            ->method('cleanupTemporaryFiles')
            ->with(['temp/sermon-permanent-fail.mp3']);

        $this->app->instance(VideoStorageService::class, $storageService);

        (new CleanupTemporaryFiles($processingLog))->handle($storageService);

        $processingLog->refresh();

        $this->assertSame(ProcessingStatus::Completed, $processingLog->status);
        $this->assertSame('notification_failed_permanently', $processingLog->current_step);
        $this->assertSame(
            'Notification failed permanently: Connection timed out',
            $processingLog->error_message
        );
        $this->assertNotNull($processingLog->completed_at);
    }

    #[Test]
    public function it_characterizes_the_final_log_state_when_notification_failure_is_followed_by_cleanup(): void
    {
        Config::set('media-processing.email.send_success_notifications', true);
        Config::set('media-processing.email.admin_email', 'admin@example.com');

        $sermon = Sermon::factory()->create([
            'title' => 'Completed Sermon',
            'slug' => 'completed-sermon',
        ]);

        $processingLog = MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => $sermon->id,
            'source_file_path' => 'temp/completed-sermon.mp3',
            'status' => ProcessingStatus::Processing,
            'current_step' => 'analyzing_transcript',
            'error_message' => null,
        ]);

        Mail::shouldReceive('raw')
            ->once()
            ->andThrow(new \RuntimeException('SMTP transport unavailable'));

        $storageService = $this->createMock(VideoStorageService::class);
        $storageService->expects($this->once())
            ->method('cleanupTemporaryFiles')
            ->with(['temp/completed-sermon.mp3']);

        $this->app->instance(VideoStorageService::class, $storageService);

        (new SendCompletionNotification($processingLog))->handle();

        $processingLog->refresh();

        $this->assertSame(ProcessingStatus::Processing, $processingLog->status);
        $this->assertSame('notification_failed', $processingLog->current_step);
        $this->assertSame(
            'Notification failed: SMTP transport unavailable',
            $processingLog->error_message
        );

        (new CleanupTemporaryFiles($processingLog))->handle($storageService);

        $processingLog->refresh();

        $this->assertSame(ProcessingStatus::Completed, $processingLog->status);
        $this->assertSame('notification_failed', $processingLog->current_step);
        $this->assertSame(
            'Notification failed: SMTP transport unavailable',
            $processingLog->error_message
        );
        $this->assertNotNull($processingLog->completed_at);
    }
}
