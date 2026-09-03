<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Data\ProcessingResult;
use App\Enums\ProcessingStatus;
use App\Models\ChurchService;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Services\Processing\ProcessingRunOrchestrator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RedetectHistoricServiceStructureCommandTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_dispatches_a_run_whose_structure_predates_the_sermon_absence_schema(): void
    {
        $run = $this->strandedHistoricRun();

        $orchestrator = Mockery::mock(ProcessingRunOrchestrator::class);
        $orchestrator->shouldReceive('retry')->once()->andReturn(
            ProcessingResult::success(
                processingId: $run->processing_id,
                message: 'dispatched',
            ),
        );
        $this->app->instance(ProcessingRunOrchestrator::class, $orchestrator);

        $this->artisan('historic-import:redetect-structure', [
            'run' => [$run->id],
            '--execute' => true,
        ])->assertSuccessful();

        $this->assertSame('detect_service_structure', $run->fresh()->current_step);
    }

    #[Test]
    public function a_dry_run_changes_nothing(): void
    {
        $run = $this->strandedHistoricRun();

        $orchestrator = Mockery::mock(ProcessingRunOrchestrator::class);
        $orchestrator->shouldNotReceive('retry');
        $this->app->instance(ProcessingRunOrchestrator::class, $orchestrator);

        $this->artisan('historic-import:redetect-structure', ['run' => [$run->id]])
            ->assertSuccessful();

        $this->assertSame('manual_review_required', $run->fresh()->current_step);
    }

    #[Test]
    public function it_refuses_a_structure_that_already_asserts_absence(): void
    {
        $run = $this->strandedHistoricRun(sermonAbsence: [
            'occasion' => 'mission_presentation',
            'explanation' => 'A visiting mission presented instead of a sermon.',
        ]);

        $this->assertSkipped($run, 'already asserts sermon absence');
    }

    #[Test]
    public function it_refuses_a_structure_that_already_names_a_sermon(): void
    {
        $run = $this->strandedHistoricRun(sections: [[
            'type' => 'sermon',
            'title' => 'Serving God by his grace',
            'start_time' => 100.0,
            'end_time' => 200.0,
        ]]);

        $this->assertSkipped($run, 'already names a sermon section');
    }

    #[Test]
    public function it_refuses_a_run_held_for_an_unrelated_reason(): void
    {
        $run = $this->strandedHistoricRun(reasonCode: 'llm_structure_validation_failed');

        $this->assertSkipped($run, 'is not re-derivable');
    }

    #[Test]
    public function it_refuses_a_run_that_is_not_historic(): void
    {
        $run = $this->strandedHistoricRun();
        $run->forceFill(['historic_import_operation_id' => null])->saveQuietly();

        $this->assertSkipped($run, 'not a historic import run');
    }

    private function assertSkipped(MediaProcessingLog $run, string $expectedReason): void
    {
        $orchestrator = Mockery::mock(ProcessingRunOrchestrator::class);
        $orchestrator->shouldNotReceive('retry');
        $this->app->instance(ProcessingRunOrchestrator::class, $orchestrator);

        $this->artisan('historic-import:redetect-structure', [
            'run' => [$run->id],
            '--execute' => true,
        ])
            ->expectsOutputToContain($expectedReason)
            ->assertSuccessful();

        $this->assertSame('manual_review_required', $run->fresh()->current_step);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @param  array<string, mixed>|null  $sermonAbsence
     */
    private function strandedHistoricRun(
        array $sections = [],
        ?array $sermonAbsence = null,
        string $reasonCode = 'candidate_exceeds_maximum_duration',
    ): MediaProcessingLog {
        $service = ChurchService::factory()->create();
        $operation = HistoricImportOperation::query()->create([
            'operation_id' => 'historic-'.str_repeat('a', 32),
            'binding_hash' => str_repeat('b', 64),
            'batch_key' => 'historic-redetect-structure',
            'manifest_hashes' => ['video' => str_repeat('c', 64)],
            'plan_hash' => str_repeat('d', 64),
            'target_fingerprint' => str_repeat('e', 64),
            'runtime_fingerprint' => str_repeat('f', 64),
            'notification_mode' => 'external_disabled',
            'max_cost_minor_units' => 100,
        ]);

        return MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'historic_import_operation_id' => $operation->id,
            'status' => ProcessingStatus::Failed,
            'current_step' => 'manual_review_required',
            'processing_metadata' => [
                'manual_review' => [
                    'status' => 'required',
                    'reason_code' => $reasonCode,
                    'reason_message' => 'Held for review.',
                ],
                'service_structure' => [
                    'sections' => $sections,
                    'sermon_absence' => $sermonAbsence,
                ],
            ],
        ]);
    }
}
