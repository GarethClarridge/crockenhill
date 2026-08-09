<?php

declare(strict_types=1);

namespace Tests\Integration\Services\Processing;

use App\Jobs\SendCompletionNotification;
use App\Mail\LivestreamProcessingFailed;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Processing\ProcessingNotificationRouter;
use App\Services\Processing\ProcessingRunFailureHandler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class HistoricNotificationIsolationTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use DatabaseTransactions;

    #[Test]
    public function historic_success_and_failure_are_private_durable_facts_even_when_live_mail_is_enabled(): void
    {
        Mail::fake();
        config([
            'media-processing.email.send_success_notifications' => true,
            'media-processing.email.send_failure_notifications' => true,
            'media-processing.email.admin_email' => 'admin@example.com',
        ]);
        $operation = $this->createHistoricImportOperation();
        $sermon = Sermon::factory()->create();
        $success = $this->historicLog($operation->id, $operation->operation_id, ['sermon_id' => $sermon->id]);

        (new SendCompletionNotification($success))->handle();

        $failure = $this->historicLog($operation->id, $operation->operation_id, ['source_file_path' => null]);
        app(ProcessingRunFailureHandler::class)->handle(
            $failure->processing_id,
            new RuntimeException('private provider failure'),
            ProcessingRunFailureHandler::PROFILE_LIVESTREAM,
        );

        Mail::assertNothingSent();
        Mail::assertNotQueued(LivestreamProcessingFailed::class);
        $this->assertSame(['failure', 'success'], $operation->alerts()->orderBy('kind')->pluck('kind')->all());
        $this->assertSame(2, $operation->journalEntries()->where('event', 'notification_suppressed')->count());
    }

    #[Test]
    public function ordinary_failure_notifications_still_send_and_the_failure_toggle_is_effective(): void
    {
        Mail::fake();
        config([
            'media-processing.email.admin_email' => 'admin@example.com',
            'media-processing.email.send_failure_notifications' => true,
        ]);
        $first = MediaProcessingLog::factory()->livestream()->processing()->create(['source_file_path' => null]);
        app(ProcessingRunFailureHandler::class)->handle(
            $first->processing_id,
            new RuntimeException('live failure'),
            ProcessingRunFailureHandler::PROFILE_LIVESTREAM,
        );
        Mail::assertQueued(LivestreamProcessingFailed::class, 1);

        config(['media-processing.email.send_failure_notifications' => false]);
        $second = MediaProcessingLog::factory()->livestream()->processing()->create(['source_file_path' => null]);
        app(ProcessingRunFailureHandler::class)->handle(
            $second->processing_id,
            new RuntimeException('muted live failure'),
            ProcessingRunFailureHandler::PROFILE_LIVESTREAM,
        );
        Mail::assertQueued(LivestreamProcessingFailed::class, 1);
    }

    #[Test]
    public function a_historic_marker_without_an_operation_binding_fails_closed(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'processing_metadata' => ['historic_import' => ['job_key' => 'historic-job']],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no immutable operation binding');

        app(ProcessingNotificationRouter::class)->suppressIfHistoric($log, 'failure', 'error', []);
    }

    /** @param array<string, mixed> $attributes */
    private function historicLog(int $operationDatabaseId, string $operationId, array $attributes = []): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->livestream()->processing()->create([
            'historic_import_operation_id' => $operationDatabaseId,
            'processing_metadata' => [
                'historic_import' => [
                    'operation_id' => $operationId,
                    'job_key' => 'job-'.uniqid(),
                ],
            ],
            ...$attributes,
        ]);
    }
}
