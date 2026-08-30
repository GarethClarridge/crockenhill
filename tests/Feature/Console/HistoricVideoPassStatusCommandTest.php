<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricVideoPassStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
