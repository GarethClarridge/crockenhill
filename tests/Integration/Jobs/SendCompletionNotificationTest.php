<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Jobs\SendCompletionNotification;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SendCompletionNotificationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_sends_email_when_notifications_enabled(): void
    {
        config([
            'media-processing.email.send_success_notifications' => true,
            'media-processing.email.admin_email' => 'admin@example.com',
        ]);

        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->processing()->create([
            'sermon_id' => $sermon->id,
        ]);

        Mail::shouldReceive('raw')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::type('callable'));

        Log::shouldReceive('info')->atLeast()->once();

        $job = new SendCompletionNotification($log);
        $job->handle();

        $log->refresh();
        $this->assertEquals('notification_sent', $log->current_step);
    }

    #[Test]
    public function it_skips_when_notifications_disabled(): void
    {
        config([
            'media-processing.email.send_success_notifications' => false,
        ]);

        $log = MediaProcessingLog::factory()->processing()->create();

        Log::shouldReceive('info')->atLeast()->once();

        $job = new SendCompletionNotification($log);
        $job->handle();

        $log->refresh();
        $this->assertEquals('notification_skipped', $log->current_step);
    }

    #[Test]
    public function it_marks_notification_as_failed_when_sermon_context_is_missing(): void
    {
        config([
            'media-processing.email.send_success_notifications' => true,
            'media-processing.email.admin_email' => 'admin@example.com',
        ]);

        $log = MediaProcessingLog::factory()->processing()->create([
            'sermon_id' => null,
        ]);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->once();
        Log::shouldReceive('warning')->once();

        $job = new SendCompletionNotification($log);
        $job->handle();

        $log->refresh();
        $this->assertEquals('notification_failed', $log->current_step);
        $this->assertStringContainsString('Notification failed:', (string) $log->error_message);
    }

    #[Test]
    public function it_skips_with_notification_skipped_when_admin_email_not_configured(): void
    {
        config([
            'media-processing.email.send_success_notifications' => true,
            'media-processing.email.admin_email' => null,
        ]);

        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->processing()->create([
            'sermon_id' => $sermon->id,
        ]);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->once();

        $job = new SendCompletionNotification($log);
        $job->handle();

        $log->refresh();
        $this->assertEquals('notification_skipped', $log->current_step);
    }

    #[Test]
    public function it_does_not_rethrow_on_mail_failure(): void
    {
        config([
            'media-processing.email.send_success_notifications' => true,
            'media-processing.email.admin_email' => 'admin@example.com',
        ]);

        Mail::shouldReceive('raw')
            ->once()
            ->andThrow(new \Exception('SMTP connection refused'));

        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->processing()->create([
            'sermon_id' => $sermon->id,
        ]);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->once();
        Log::shouldReceive('warning')->once();

        // Should NOT throw - the inner try/catch absorbs the mail error
        $job = new SendCompletionNotification($log);
        $job->handle();

        $log->refresh();
        $this->assertEquals('notification_failed', $log->current_step);
        $this->assertSame(
            'Notification failed: SMTP connection refused',
            $log->error_message
        );
    }

    #[Test]
    public function it_has_correct_backoff_configuration(): void
    {
        $log = MediaProcessingLog::factory()->create();

        $job = new SendCompletionNotification($log);

        $this->assertEquals([30, 120, 300], $job->backoff());
    }

    #[Test]
    public function it_has_correct_job_configuration(): void
    {
        $log = MediaProcessingLog::factory()->create();

        $job = new SendCompletionNotification($log);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals(120, $job->timeout);
    }

    #[Test]
    public function it_skips_all_work_when_processing_is_cancelled(): void
    {
        Mail::fake();

        $log = MediaProcessingLog::factory()->cancelled()->create();

        Log::shouldReceive('info')->once()->withArgs(fn ($msg) => str_contains($msg, 'job skipped: processing cancelled'));

        $job = new SendCompletionNotification($log);
        $job->handle();

        Mail::assertNothingSent();
    }

    #[Test]
    public function failed_method_updates_processing_log(): void
    {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->processing()->create([
            'sermon_id' => $sermon->id,
        ]);

        Log::shouldReceive('error')->once();

        $job = new SendCompletionNotification($log);
        $job->failed(new \Exception('Permanent failure'));

        $log->refresh();
        $this->assertStringContainsString('notification_failed', $log->current_step);
    }
}
