<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Data\SermonAnalysis;
use App\Enums\ProcessingStatus;
use App\Jobs\ProcessTranscriptWithAI;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class ReanalyseHistoricDegradedCompletionsCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use DatabaseTransactions;

    private function degradedRun(int $operationId, array $overrides = []): MediaProcessingLog
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Sunday 26Th January 2025 [Youtube Backup]',
            'reference' => null,
            'summary' => null,
            'points' => ['Main Message'],
        ]);

        return MediaProcessingLog::factory()->audio()->create(array_merge([
            'historic_import_operation_id' => $operationId,
            'sermon_id' => $sermon->id,
            'status' => ProcessingStatus::Completed,
            'is_degraded_completion' => true,
            'ai_analysis' => SermonAnalysis::create(
                title: 'Sunday 26Th January 2025',
                series: null,
                reference: null,
                points: ['Main Message'],
                summary: null,
                transcript: '',
            ),
        ], $overrides));
    }

    #[Test]
    public function it_dispatches_reanalysis_for_every_named_degraded_run_on_the_calibrated_queue(): void
    {
        Queue::fake();

        $operation = $this->createHistoricImportOperation();
        $first = $this->degradedRun($operation->id);
        $second = $this->degradedRun($operation->id);

        $this->artisan('historic-import:reanalyse-degraded-completions', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$first->processing_id, $second->processing_id],
        ])
            ->expectsOutputToContain('2 re-analysis job(s) dispatched')
            ->assertSuccessful();

        Queue::assertPushed(ProcessTranscriptWithAI::class, 2);
        Queue::assertPushedOn('historic-llm', ProcessTranscriptWithAI::class);
    }

    #[Test]
    public function it_refuses_a_run_that_is_not_a_completed_degraded_completion(): void
    {
        Queue::fake();

        $operation = $this->createHistoricImportOperation();
        $clean = $this->degradedRun($operation->id, ['is_degraded_completion' => false]);

        $this->artisan('historic-import:reanalyse-degraded-completions', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$clean->processing_id],
        ])
            ->expectsOutputToContain('must be a completed degraded completion')
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_refuses_a_run_outside_the_named_operation(): void
    {
        Queue::fake();

        $operation = $this->createHistoricImportOperation();
        $otherOperation = $this->createHistoricImportOperation(str_repeat('d', 64));
        $run = $this->degradedRun($otherOperation->id);

        $this->artisan('historic-import:reanalyse-degraded-completions', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$run->processing_id],
        ])
            ->expectsOutputToContain('must belong to the named historic operation')
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_requires_at_least_one_processing_id(): void
    {
        $operation = $this->createHistoricImportOperation();

        $this->artisan('historic-import:reanalyse-degraded-completions', [
            '--operation' => $operation->operation_id,
        ])
            ->expectsOutputToContain('At least one exact degraded completed --processing-id is required')
            ->assertFailed();
    }
}
