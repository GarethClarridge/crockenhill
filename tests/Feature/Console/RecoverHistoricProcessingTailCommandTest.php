<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ProcessingStatus;
use App\Jobs\AnalyzeSegments;
use App\Jobs\AwaitHistoricSermonVideoStorage;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\PromoteHistoricAssets;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricStagingGuard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class RecoverHistoricProcessingTailCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use DatabaseTransactions;

    private string $stagingRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stagingRoot = sys_get_temp_dir().'/historic-tail-recovery-'.str()->random(12);
        mkdir($this->stagingRoot, 0755, true);

        config([
            'filesystems.disks.historic_staging.root' => $this->stagingRoot,
            'media-processing.storage.historic_staging_disk' => 'historic_staging',
            'media-processing.storage.sermon_disk' => 'historic_staging',
            'media-processing.storage.transcript_disk' => 'historic_staging',
        ]);
        Storage::forgetDisk('historic_staging');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->stagingRoot);

        parent::tearDown();
    }

    #[Test]
    public function it_dispatches_only_the_historic_promotion_and_cleanup_tail(): void
    {
        Bus::fake();

        $operation = $this->createHistoricImportOperation();
        $processingLog = $this->staleHistoricRun($operation);

        $this->artisan('historic-import:recover-processing-tail', [
            'processing_id' => $processingLog->processing_id,
            '--operation' => $operation->operation_id,
        ])
            ->expectsOutputToContain('Historic promotion and cleanup tail dispatched')
            ->assertSuccessful();

        Bus::assertChained([
            AwaitHistoricSermonVideoStorage::class,
            PromoteHistoricAssets::class,
            CleanupTemporaryFiles::class,
        ]);
        Bus::assertNotDispatched(AnalyzeSegments::class);
        Bus::assertNotDispatched(ProcessTranscriptWithAI::class);
    }

    #[Test]
    public function repeating_recovery_is_a_no_op_after_the_first_atomic_claim(): void
    {
        Bus::fake();

        $operation = $this->createHistoricImportOperation();
        $processingLog = $this->staleHistoricRun($operation);
        $arguments = [
            'processing_id' => $processingLog->processing_id,
            '--operation' => $operation->operation_id,
        ];

        $this->artisan('historic-import:recover-processing-tail', $arguments)
            ->assertSuccessful();

        $this->artisan('historic-import:recover-processing-tail', $arguments)
            ->expectsOutputToContain('already claimed')
            ->assertSuccessful();

        $processingLog->refresh();
        self::assertSame(
            $operation->operation_id,
            data_get($processingLog->processing_metadata?->toArray(), 'historic_tail_recovery.operation_id'),
        );
        self::assertCount(1, Bus::dispatched(AwaitHistoricSermonVideoStorage::class));
    }

    #[Test]
    public function it_refuses_a_fresh_processing_run(): void
    {
        Bus::fake();

        $operation = $this->createHistoricImportOperation();
        $processingLog = $this->staleHistoricRun($operation, [
            'started_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(1),
        ]);

        $this->artisan('historic-import:recover-processing-tail', [
            'processing_id' => $processingLog->processing_id,
            '--operation' => $operation->operation_id,
        ])
            ->expectsOutputToContain('not stale')
            ->assertFailed();

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_refuses_non_historic_wrong_operation_terminal_and_non_tail_runs(): void
    {
        Bus::fake();

        $operation = $this->createHistoricImportOperation();
        $otherOperation = $this->createHistoricImportOperation();

        $cases = [
            'non_historic' => MediaProcessingLog::factory()->livestream()->processing()->create([
                'current_step' => 'notification_skipped',
                'started_at' => now()->subHours(2),
                'updated_at' => now()->subHours(2),
            ]),
            'wrong_operation' => $this->staleHistoricRun($otherOperation),
            'terminal' => $this->staleHistoricRun($operation, [
                'status' => ProcessingStatus::Completed,
                'current_step' => 'completed',
            ]),
            'wrong_step' => $this->staleHistoricRun($operation, [
                'current_step' => 'analyzing_transcript',
            ]),
        ];

        foreach ($cases as $name => $processingLog) {
            $this->artisan('historic-import:recover-processing-tail', [
                'processing_id' => $processingLog->processing_id,
                '--operation' => $operation->operation_id,
            ])
                ->expectsOutputToContain(match ($name) {
                    'non_historic' => 'not a historic run',
                    'wrong_operation' => 'does not belong to the named historic operation',
                    'terminal' => 'must still be processing',
                    default => 'not at the promotion/cleanup tail',
                })
                ->assertFailed();
        }

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_refuses_a_run_whose_metadata_operation_identity_does_not_match_its_foreign_key(): void
    {
        Bus::fake();

        $operation = $this->createHistoricImportOperation();
        $otherOperation = $this->createHistoricImportOperation();
        $stagingContext = app(HistoricStagingGuard::class)->contextForApprovedPlan(
            str_repeat('a', 64),
            str_repeat('b', 64),
        );
        $processingLog = $this->staleHistoricRun($operation, [
            'processing_metadata' => [
                'historic_import' => [
                    'job_key' => 'historic-tail-job',
                    'operation_id' => $otherOperation->operation_id,
                    'staging_context' => $stagingContext->toArray(),
                ],
            ],
        ]);

        $this->artisan('historic-import:recover-processing-tail', [
            'processing_id' => $processingLog->processing_id,
            '--operation' => $operation->operation_id,
        ])
            ->expectsOutputToContain('metadata operation identity does not match')
            ->assertFailed();

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_refuses_a_staging_context_bound_to_another_manifest_or_plan(): void
    {
        Bus::fake();

        $operation = $this->createHistoricImportOperation();
        $contexts = [
            app(HistoricStagingGuard::class)->contextForApprovedPlan(
                str_repeat('d', 64),
                str_repeat('b', 64),
            ),
            app(HistoricStagingGuard::class)->contextForApprovedPlan(
                str_repeat('a', 64),
                str_repeat('e', 64),
            ),
        ];

        foreach ($contexts as $context) {
            $processingLog = $this->staleHistoricRun($operation, [
                'processing_metadata' => [
                    'historic_import' => [
                        'job_key' => 'historic-tail-job-'.str()->random(8),
                        'operation_id' => $operation->operation_id,
                        'staging_context' => $context->toArray(),
                    ],
                ],
            ]);

            $this->artisan('historic-import:recover-processing-tail', [
                'processing_id' => $processingLog->processing_id,
                '--operation' => $operation->operation_id,
            ])
                ->expectsOutputToContain('staging context manifest and plan hashes do not match')
                ->assertFailed();
        }

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_recovers_a_run_that_failed_under_its_own_tail_claim(): void
    {
        Bus::fake();

        $operation = $this->createHistoricImportOperation();
        $processingLog = $this->failedTailAttempt($operation);

        $this->artisan('historic-import:recover-processing-tail', [
            'processing_id' => $processingLog->processing_id,
            '--operation' => $operation->operation_id,
        ])
            ->expectsOutputToContain('Historic promotion and cleanup tail dispatched')
            ->assertSuccessful();

        Bus::assertChained([
            AwaitHistoricSermonVideoStorage::class,
            PromoteHistoricAssets::class,
            CleanupTemporaryFiles::class,
        ]);

        $startedAt = $processingLog->started_at;
        $processingLog->refresh();
        self::assertSame(ProcessingStatus::Processing, $processingLog->status);

        // Reopening must clear the terminal fields with the status. A run left
        // holding completed_at and error_message while it is processing reads
        // as simultaneously active and failed in the status report and in the
        // performance evidence taken from these columns.
        self::assertNull($processingLog->completed_at);
        self::assertNull($processingLog->error_message);
        self::assertTrue(
            $startedAt?->equalTo($processingLog->started_at) ?? false,
            'started_at is the run\'s real start and must survive a reopen.',
        );
    }

    #[Test]
    public function it_still_refuses_a_failed_run_that_never_claimed_the_tail(): void
    {
        Bus::fake();

        $operation = $this->createHistoricImportOperation();
        $processingLog = $this->staleHistoricRun($operation, [
            'status' => ProcessingStatus::Failed,
            'current_step' => 'sermon_submitted',
        ]);

        $this->artisan('historic-import:recover-processing-tail', [
            'processing_id' => $processingLog->processing_id,
            '--operation' => $operation->operation_id,
        ])
            ->expectsOutputToContain('must still be processing')
            ->assertFailed();

        Bus::assertNothingDispatched();
    }

    /**
     * A run whose tail chain failed at its first link: the await gate marks the
     * run failed and records SermonSubmitted, leaving the claim behind.
     */
    private function failedTailAttempt(HistoricImportOperation $operation): MediaProcessingLog
    {
        $processingLog = $this->staleHistoricRun($operation);
        $metadata = $processingLog->processing_metadata?->toArray() ?? [];
        $metadata['historic_tail_recovery'] = [
            'operation_id' => $operation->operation_id,
            'processing_id' => $processingLog->processing_id,
            'job_key' => $processingLog->historicImportJobKey(),
            'claimed_at' => Carbon::now()->subHour()->toISOString(),
        ];

        $processingLog->forceFill([
            'processing_metadata' => $metadata,
            'status' => ProcessingStatus::Failed,
            'current_step' => 'sermon_submitted',
            // The terminal fields markAsFailed() writes alongside the status.
            'completed_at' => Carbon::now()->subMinutes(30),
            'error_message' => 'Historic sermon video never reached its operation-owned key.',
        ])->save();

        return $processingLog->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function staleHistoricRun(
        HistoricImportOperation $operation,
        array $attributes = [],
    ): MediaProcessingLog {
        $staleAt = Carbon::now()->subHours(2);
        $stagingContext = app(HistoricStagingGuard::class)->contextForApprovedPlan(
            str_repeat('a', 64),
            str_repeat('b', 64),
        );

        return MediaProcessingLog::factory()->livestream()->processing()->create([
            'historic_import_operation_id' => $operation->id,
            'processing_id' => 'historic-tail-'.str()->random(12),
            'current_step' => 'notification_skipped',
            'started_at' => $staleAt,
            'updated_at' => $staleAt,
            'processing_metadata' => [
                'historic_import' => [
                    'job_key' => 'historic-tail-job-'.str()->random(8),
                    'operation_id' => $operation->operation_id,
                    'staging_context' => $stagingContext->toArray(),
                ],
            ],
            ...$attributes,
        ]);
    }
}
