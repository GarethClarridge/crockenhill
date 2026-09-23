<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Data\HistoricStagingContext;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\Processing\ProcessingRunOrchestrator;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class RetranscribeHistoricVideoRunCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use DatabaseTransactions;

    private const Source = 'temp/historic-source.webm';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Config::set('media-processing.storage.temp_disk', 'local');
        MediaProcessingLog::query()
            ->whereIn('status', [
                ProcessingStatus::Pending->value,
                ProcessingStatus::Started->value,
                ProcessingStatus::Processing->value,
            ])
            ->update(['status' => ProcessingStatus::Completed->value]);
    }

    #[Test]
    public function it_is_a_dry_run_by_default(): void
    {
        $run = $this->completedRun();
        $this->fakeStagingContext();

        $orchestrator = Mockery::mock(ProcessingRunOrchestrator::class);
        $orchestrator->shouldNotReceive('start');
        $this->app->instance(ProcessingRunOrchestrator::class, $orchestrator);

        $this->artisan('historic-import:retranscribe-video-run', ['run' => $run->id])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('ready')
            ->assertSuccessful();

        self::assertSame(ProcessingStatus::Completed, $run->fresh()?->status);
        self::assertFalse($run->fresh()?->isReExtraction());
    }

    #[Test]
    public function it_reopens_and_starts_the_selected_run_from_full_service_transcription(): void
    {
        $run = $this->completedRun();
        $this->fakeStagingContext();

        $orchestrator = Mockery::mock(ProcessingRunOrchestrator::class);
        $orchestrator->shouldReceive('start')
            ->once()
            ->with(Mockery::on(static fn (MediaProcessingLog $candidate): bool => $candidate->is($run)
                && $candidate->status === ProcessingStatus::Processing
                && $candidate->current_step === 'transcribe_full_service'
                && $candidate->isReExtraction()), false);
        $this->app->instance(ProcessingRunOrchestrator::class, $orchestrator);

        $this->artisan('historic-import:retranscribe-video-run', [
            'run' => $run->id,
            '--execute' => true,
        ])
            ->expectsOutputToContain('dispatched')
            ->assertSuccessful();

        $fresh = $run->fresh();

        self::assertSame(ProcessingStatus::Processing, $fresh?->status);
        self::assertSame('transcribe_full_service', $fresh?->current_step);
        self::assertTrue($fresh?->isReExtraction());
        self::assertNull($fresh?->completed_at);
    }

    #[Test]
    public function it_refuses_when_the_staged_source_is_missing(): void
    {
        $run = $this->completedRun(withSource: false);
        $this->fakeStagingContext();

        $this->assertRefused($run, 'staged source is missing');
    }

    #[Test]
    public function it_refuses_a_run_without_a_staging_context(): void
    {
        $run = $this->completedRun(withStagingContext: false);

        $this->assertRefused($run, 'no historic staging context');
    }

    #[Test]
    public function it_refuses_a_run_that_is_not_completed(): void
    {
        $run = $this->completedRun(status: ProcessingStatus::Failed);
        $this->fakeStagingContext();

        $this->assertRefused($run, 'is failed, not completed');
    }

    #[Test]
    public function it_refuses_while_another_processing_run_is_active(): void
    {
        $run = $this->completedRun();
        MediaProcessingLog::factory()->livestream()->create([
            'status' => ProcessingStatus::Processing,
        ]);
        $this->fakeStagingContext();

        $this->assertRefused($run, 'another run is active');
    }

    /**
     * 1287 (2026-09-23): the stored transcript lost song 2 to context drift, so the
     * detector split song 1 across two sections. The re-decode hears both songs.
     */
    #[Test]
    public function it_accepts_run_1287(): void
    {
        $run = $this->completedRun(id: 1287);
        $this->fakeStagingContext();

        $this->artisan('historic-import:retranscribe-video-run', ['run' => $run->id])
            ->expectsOutputToContain('ready')
            ->assertSuccessful();
    }

    #[Test]
    public function it_refuses_a_run_outside_the_approved_set(): void
    {
        $run = $this->completedRun(id: 1342);

        $this->assertRefused($run, 'outside the approved');
    }

    #[Test]
    public function it_refuses_when_the_staged_source_hash_does_not_match(): void
    {
        $run = $this->completedRun(attributes: ['file_hash' => str_repeat('f', 64)]);
        $this->fakeStagingContext();

        $this->assertRefused($run, 'hash does not match');
    }

    #[Test]
    public function it_refuses_stale_recovery_replay_provenance(): void
    {
        $run = $this->completedRun(attributes: [
            'processing_metadata' => [
                'historic_import' => [
                    'operation_id' => 'replaced below',
                    'staging_context' => $this->stagingContext()->toArray(),
                ],
                'transcript_recovery_replay' => ['replayed_at' => now()->toIso8601String()],
            ],
        ]);
        $metadata = $run->processing_metadata?->toArray() ?? [];
        data_set($metadata, 'historic_import.operation_id', $run->historicImportOperation?->operation_id);
        $run->update(['processing_metadata' => $metadata]);
        $this->fakeStagingContext();

        $this->assertRefused($run, 'stale transcript recovery replay');
    }

    #[Test]
    public function it_fails_for_an_unknown_run(): void
    {
        $this->artisan('historic-import:retranscribe-video-run', ['run' => 999_999])
            ->expectsOutputToContain('not found')
            ->assertFailed();
    }

    private function assertRefused(MediaProcessingLog $run, string $reason): void
    {
        $orchestrator = Mockery::mock(ProcessingRunOrchestrator::class);
        $orchestrator->shouldNotReceive('start');
        $this->app->instance(ProcessingRunOrchestrator::class, $orchestrator);

        $this->artisan('historic-import:retranscribe-video-run', [
            'run' => $run->id,
            '--execute' => true,
        ])
            ->expectsOutputToContain($reason)
            ->assertFailed();

        self::assertSame($run->status, $run->fresh()?->status);
        self::assertFalse($run->fresh()?->isReExtraction());
    }

    private function completedRun(
        bool $withSource = true,
        bool $withStagingContext = true,
        ProcessingStatus $status = ProcessingStatus::Completed,
        int $id = 1343,
        array $attributes = [],
    ): MediaProcessingLog {
        $operation = $this->createHistoricImportOperation();
        $metadata = ['historic_import' => ['operation_id' => $operation->operation_id]];

        if ($withStagingContext) {
            $metadata['historic_import']['staging_context'] = $this->stagingContext()->toArray();
        }

        $run = MediaProcessingLog::factory()->livestream()->create([
            'id' => $id,
            'historic_import_operation_id' => $operation->id,
            'status' => $status,
            'current_step' => $status === ProcessingStatus::Completed ? 'completed' : 'failed',
            'source_file_path' => self::Source,
            'file_hash' => hash('sha256', 'source bytes'),
            'completed_at' => now(),
            'processing_metadata' => $metadata,
            ...$attributes,
        ]);

        if ($withSource) {
            Storage::disk('local')->put(self::Source, 'source bytes');
        }

        return $run;
    }

    private function fakeStagingContext(): void
    {
        $registry = Mockery::mock(HistoricStagingContextRegistry::class);
        $registry->shouldReceive('within')
            ->andReturnUsing(static fn (HistoricStagingContext $context, Closure $callback): mixed => $callback());
        $this->app->instance(HistoricStagingContextRegistry::class, $registry);
    }

    private function stagingContext(): HistoricStagingContext
    {
        return new HistoricStagingContext(
            manifestHash: str_repeat('a', 64),
            planHash: str_repeat('b', 64),
            stagingDisk: 'historic_staging',
            batchRoot: 'historic-batches/retranscription',
            storageIdentity: [
                'driver' => 'local',
                'bucket' => null,
                'root_fingerprint' => str_repeat('c', 64),
                'prefix_fingerprint' => str_repeat('d', 64),
            ],
        );
    }
}
