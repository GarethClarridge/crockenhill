<?php

declare(strict_types=1);

namespace Tests\Feature\MediaProcessing;

use App\Enums\ProcessingStatus;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\SendCompletionNotification;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\VideoStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompletionOutcomePreservationTest extends TestCase
{
    use RefreshDatabase;

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
            'status' => ProcessingStatus::PROCESSING,
            'current_step' => 'updating_sermon_record',
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

        $this->assertSame(ProcessingStatus::PROCESSING, $processingLog->status);
        $this->assertSame('notification_failed', $processingLog->current_step);
        $this->assertSame(
            'Notification failed: SMTP transport unavailable',
            $processingLog->error_message
        );

        (new CleanupTemporaryFiles($processingLog))->handle($storageService);

        $processingLog->refresh();

        $this->assertSame(ProcessingStatus::COMPLETED, $processingLog->status);
        $this->assertSame('notification_failed', $processingLog->current_step);
        $this->assertSame(
            'Notification failed: SMTP transport unavailable',
            $processingLog->error_message
        );
        $this->assertNotNull($processingLog->completed_at);
    }
}
