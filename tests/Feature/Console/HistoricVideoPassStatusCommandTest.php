<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricVideoPassStatus;
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
