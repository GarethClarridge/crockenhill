<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricVideoPassStatus;
use App\Services\Processing\ProcessingNotificationRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class HistoricVideoPassStatusCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    #[Test]
    public function it_reports_truthful_database_dispositions_for_the_selected_pass_items(): void
    {
        $operation = $this->createHistoricImportOperation();

        $this->createRun($operation->id, 'finished', ProcessingStatus::Completed, 'completed');
        $this->createRun($operation->id, 'review', ProcessingStatus::Failed, 'manual_review_required');
        $this->createRun($operation->id, 'running', ProcessingStatus::Processing, 'transcribing_audio');

        $report = app(HistoricVideoPassStatus::class)->report($operation, ['finished', 'review', 'running', 'not-dispatched']);
        self::assertSame('completed', $report[0]['disposition']);
        self::assertSame('manual_review', $report[1]['disposition']);
        self::assertSame('in_progress', $report[2]['disposition']);
        self::assertSame('not_dispatched', $report[3]['disposition']);

        $this->artisan('historic-import:video-pass-status', [
            '--operation' => $operation->operation_id,
            '--only' => 'finished,review,running,not-dispatched',
        ])
            ->expectsOutputToContain('Database-owned pass status')
            ->assertExitCode(0);
    }

    /**
     * A degraded completion is the one outcome that reads as success while containing none of the
     * work: `ProcessTranscriptWithAI` substituted empty analysis, so the banked sermon has no
     * reference, no summary and a filename for a title. Reporting it as `completed` is how the
     * 2026-09-02 pass presented six hollow sermons as its only successes, and why Phase 8's exit
     * gate refuses to count one as completed.
     */
    #[Test]
    public function it_reports_degraded_not_completed_for_a_run_that_banked_substituted_analysis(): void
    {
        $operation = $this->createHistoricImportOperation();

        MediaProcessingLog::factory()->livestream()->create([
            'historic_import_operation_id' => $operation->id,
            'status' => ProcessingStatus::Completed,
            'current_step' => 'ai_analysis_fallback',
            'is_degraded_completion' => true,
            'processing_metadata' => [
                'historic_import' => ['manifest_item_key' => '2025-01-26-morning'],
            ],
        ]);

        $report = app(HistoricVideoPassStatus::class)->report($operation, ['2025-01-26-morning']);

        self::assertSame('degraded', $report[0]['disposition']);

        $this->artisan('historic-import:video-pass-status', [
            '--operation' => $operation->operation_id,
            '--only' => '2025-01-26-morning',
        ])
            ->expectsOutputToContain('do NOT count as completed for the pass gate')
            ->expectsOutputToContain('2025-01-26-morning')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_reports_mixed_terminal_when_only_some_runs_for_an_identity_degraded(): void
    {
        $operation = $this->createHistoricImportOperation();

        MediaProcessingLog::factory()->livestream()->create([
            'historic_import_operation_id' => $operation->id,
            'status' => ProcessingStatus::Completed,
            'current_step' => 'ai_analysis_fallback',
            'is_degraded_completion' => true,
            'processing_metadata' => ['historic_import' => ['manifest_item_key' => 'mixed']],
        ]);
        $this->createRun($operation->id, 'mixed', ProcessingStatus::Completed, 'completed');

        $report = app(HistoricVideoPassStatus::class)->report($operation, ['mixed']);

        self::assertSame('mixed_terminal', $report[0]['disposition']);
    }

    #[Test]
    public function it_reports_excluded_not_completed_for_a_silent_source_run(): void
    {
        $operation = $this->createHistoricImportOperation();

        $log = MediaProcessingLog::factory()->livestream()->create([
            'historic_import_operation_id' => $operation->id,
            'status' => ProcessingStatus::Completed,
            'current_step' => 'completed',
            'processing_metadata' => [
                'historic_import' => ['manifest_item_key' => '2026-04-02-evening'],
                'exclusion' => [
                    'reason' => 'source_audio_silent',
                    'recorded_at' => now()->toIso8601String(),
                    'evidence' => ['frame_count' => 21012, 'rms_log_path' => 'temp/rms.log'],
                ],
            ],
        ]);

        app(ProcessingNotificationRouter::class)->suppressIfHistoric(
            $log,
            'excluded_source_audio_silent',
            'warning',
            ['frame_count' => 21012, 'rms_log_path' => 'temp/rms.log'],
        );

        $report = app(HistoricVideoPassStatus::class)->report($operation, ['2026-04-02-evening']);
        self::assertSame('excluded', $report[0]['disposition']);

        $alerts = app(HistoricVideoPassStatus::class)->alerts($operation, ['2026-04-02-evening']);
        self::assertSame(
            ['kind' => 'excluded_source_audio_silent', 'severity' => 'warning', 'count' => 1],
            $alerts['by_kind'][0],
        );
        self::assertSame('2026-04-02-evening', $alerts['items'][0]['item_key']);
        self::assertSame(
            'source audio is digitally silent (21012 frames, all -inf)',
            $alerts['items'][0]['reason'],
        );

        // Overlapping substrings (e.g. "excluded" inside "excluded_source_
        // audio_silent") make expectsOutputToContain's per-call matching
        // unreliable across multiple assertions here, so only one distinctive,
        // non-overlapping fragment is asserted at the console level — the
        // service-level assertions above prove the actual content.
        $this->artisan('historic-import:video-pass-status', [
            '--operation' => $operation->operation_id,
            '--only' => '2026-04-02-evening',
        ])
            ->expectsOutputToContain('Historic import alerts')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_reports_the_real_diagnostic_for_failure_manual_review_and_success_alerts(): void
    {
        $operation = $this->createHistoricImportOperation();

        $failed = $this->createAlertableRun($operation->id, 'failed-item');
        $review = $this->createAlertableRun($operation->id, 'review-item');
        $succeeded = $this->createAlertableRun($operation->id, 'success-item');

        $router = app(ProcessingNotificationRouter::class);

        // The exact fact shape ProcessingRunFailureHandler writes: the real
        // cause lives in internal_message, and `message` is only the sanitised
        // placeholder meant for external mail.
        $router->suppressIfHistoric($failed, 'failure', 'error', [
            'stage' => 'notification_skipped',
            'message' => 'An internal error occurred during livestream processing.',
            'internal_message' => 'Stored full-service transcript contains no cues.',
            'exception_class' => 'RuntimeException',
            'exception_fingerprint' => hash('sha256', 'Stored full-service transcript contains no cues.'),
        ]);

        $router->suppressIfHistoric($review, 'manual_review_extraction', 'warning', [
            'reason' => 'Sermon auto-selection confidence was insufficient.',
            'speech_segments' => [],
        ]);

        $router->suppressIfHistoric($succeeded, 'success', 'info', [
            'stage' => 'processing_complete',
            'sermon_id' => 4321,
        ]);

        $alerts = app(HistoricVideoPassStatus::class)
            ->alerts($operation, ['failed-item', 'review-item', 'success-item']);

        $reasonByKind = [];
        foreach ($alerts['items'] as $item) {
            $reasonByKind[$item['kind']] = $item['reason'];
        }

        self::assertSame(
            'Stored full-service transcript contains no cues.',
            $reasonByKind['failure'],
            'A failure alert must report its internal diagnostic, not the bare kind.',
        );
        self::assertSame(
            'Sermon auto-selection confidence was insufficient.',
            $reasonByKind['manual_review_extraction'],
        );
        // A success alert carries no diagnostic text of any kind, so the kind
        // itself is the only honest thing to print.
        self::assertSame('success', $reasonByKind['success']);
    }

    #[Test]
    public function it_falls_back_to_the_safe_message_when_a_failure_alert_predates_internal_messages(): void
    {
        $operation = $this->createHistoricImportOperation();
        $log = $this->createAlertableRun($operation->id, 'legacy-item');

        // The 22 failure alerts already on disk from the canary were written
        // before D5 added internal_message; they must still read as something
        // better than "failure".
        app(ProcessingNotificationRouter::class)->suppressIfHistoric($log, 'failure', 'error', [
            'stage' => 'notification_skipped',
            'message' => 'An internal error occurred during livestream processing.',
            'exception_class' => 'RuntimeException',
        ]);

        $alerts = app(HistoricVideoPassStatus::class)->alerts($operation, ['legacy-item']);

        self::assertSame(
            'An internal error occurred during livestream processing.',
            $alerts['items'][0]['reason'],
        );
    }

    #[Test]
    public function it_reports_mixed_terminal_when_manual_review_and_system_failure_both_exist(): void
    {
        $operation = $this->createHistoricImportOperation();
        $this->createRun($operation->id, 'mixed', ProcessingStatus::Failed, 'manual_review_required');
        $this->createRun($operation->id, 'mixed', ProcessingStatus::Failed, 'extracting_audio');

        $report = app(HistoricVideoPassStatus::class)->report($operation, ['mixed']);

        self::assertSame('mixed_terminal', $report[0]['disposition']);
    }

    #[Test]
    public function it_reports_the_custody_byte_measures_on_request(): void
    {
        Storage::fake('historic_staging');
        Storage::fake('historic_quarantine');
        config()->set('media-processing.storage.historic_staging_disk', 'historic_staging');
        config()->set('media-processing.storage.historic_quarantine_disk', 'historic_quarantine');
        config()->set('media-processing.storage.sermon_disk', 'historic_staging');
        config()->set('media-processing.storage.transcript_disk', 'historic_staging');
        config()->set('thumbnail-generation.storage.disk', 'historic_staging');

        $operation = $this->createHistoricImportOperation();
        $this->createRun($operation->id, 'finished', ProcessingStatus::Completed, 'completed');

        $this->artisan('historic-import:video-pass-status', [
            '--operation' => $operation->operation_id,
            '--only' => 'finished',
            '--measures' => true,
        ])
            ->expectsOutputToContain('Peak working (sampled at promotion)')
            ->expectsOutputToContain('Unexplained residue')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_creates_a_performance_report_once_at_an_absolute_path(): void
    {
        File::ensureDirectoryExists(storage_path('scratch'));
        $operation = $this->createHistoricImportOperation();
        $this->createRun($operation->id, 'finished', ProcessingStatus::Completed, 'completed');
        $path = storage_path('scratch/m12-performance-'.uniqid().'.json');

        try {
            $this->artisan('historic-import:video-pass-status', [
                '--operation' => $operation->operation_id,
                '--only' => 'finished',
                '--performance' => true,
                '--performance-report' => $path,
            ])
                ->expectsOutputToContain('Database-owned historic-video performance')
                ->expectsOutputToContain('Performance report: '.$path)
                ->assertExitCode(0);

            $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('crockenhill.historic-video-pass-performance', $payload['format']);
            $this->assertSame($operation->operation_id, $payload['operation_id']);
            $this->assertSame(['finished'], $payload['item_keys']);
            $this->assertSame(0600, fileperms($path) & 0777);

            $this->artisan('historic-import:video-pass-status', [
                '--operation' => $operation->operation_id,
                '--only' => 'finished',
                '--performance' => true,
                '--performance-report' => $path,
            ])
                ->expectsOutputToContain('Refusing to overwrite existing performance report')
                ->assertExitCode(1);
        } finally {
            File::delete($path);
        }
    }

    #[Test]
    public function it_requires_performance_for_a_performance_report_path(): void
    {
        $this->artisan('historic-import:video-pass-status', [
            '--operation' => 'operation-not-used',
            '--only' => 'item',
            '--performance-report' => '/tmp/m12-performance.json',
        ])
            ->expectsOutputToContain('--performance-report requires --performance.')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_requires_an_operation_and_immutable_pass_membership(): void
    {
        $this->artisan('historic-import:video-pass-status')
            ->expectsOutputToContain('Both --operation and a non-empty --only manifest-key list are required.')
            ->assertExitCode(1);
    }

    private function createAlertableRun(int $operationId, string $itemKey): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->livestream()->create([
            'historic_import_operation_id' => $operationId,
            'status' => ProcessingStatus::Completed,
            'current_step' => 'completed',
            'processing_metadata' => [
                'historic_import' => ['manifest_item_key' => $itemKey],
            ],
        ]);
    }

    private function createRun(
        int $operationId,
        string $itemKey,
        ProcessingStatus $status,
        string $currentStep,
    ): void {
        MediaProcessingLog::factory()->livestream()->create([
            'historic_import_operation_id' => $operationId,
            'status' => $status,
            'current_step' => $currentStep,
            'error_message' => $currentStep === 'manual_review_required'
                ? 'Manual Review Note: Sermon auto-selection confidence was insufficient.'
                : null,
            'processing_metadata' => [
                'historic_import' => [
                    'manifest_item_key' => $itemKey,
                ],
            ],
        ]);
    }
}
