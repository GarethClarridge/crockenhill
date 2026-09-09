<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Data\ChurchServiceTranscript;
use App\Data\HistoricStagingContext;
use App\Enums\SermonPublicationState;
use App\Jobs\ProcessTranscriptWithAI;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\HistoricMedia\HistoricSermonTranscriptSpanRepair;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\HistoricMedia\HistoricStagingGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class RepairHistoricSermonTranscriptSpansCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    /**
     * The contaminated text the old outer-bounds slicing banked: the reading,
     * the hymn between the two extracted spans, and the sermon.
     */
    private const CONTAMINATED = 'The preached reading. An intervening hymn, sung twice. The sermon itself.';

    private const REPAIRED = 'The preached reading. The sermon itself.';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('historic_quarantine');
        // The configured transcript disk is deliberately NOT the sermon's own
        // disk: a promoted historic transcript lives on its quarantine disk.
        Config::set('media-processing.storage.temp_disk', 'local');
        Config::set('media-processing.storage.transcript_disk', 'local');
        Config::set('media-processing.storage.sermon_disk', 'local');
    }

    #[Test]
    public function it_reports_the_repair_without_writing_by_default(): void
    {
        [$operation, $log, $sermon] = $this->historicRun();

        $this->artisan('historic-import:repair-sermon-transcript-spans', [
            '--operation' => $operation->operation_id,
        ])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('1 transcript(s) would be repaired.')
            ->assertSuccessful();

        self::assertSame(self::CONTAMINATED, $this->savedTranscript($sermon));
        self::assertNull($log->fresh()?->processing_metadata?->toArray()['transcript_span_repair'] ?? null);
    }

    #[Test]
    public function it_repairs_in_place_on_the_sermons_own_disk(): void
    {
        [$operation, $log, $sermon] = $this->historicRun();

        $this->artisan('historic-import:repair-sermon-transcript-spans', [
            '--operation' => $operation->operation_id,
            '--execute' => true,
        ])
            ->expectsOutputToContain('Repaired 1 transcript(s).')
            ->assertSuccessful();

        self::assertSame(self::REPAIRED, $this->savedTranscript($sermon));
        // Writing through the configured transcript disk would orphan the new
        // text and leave the sermon pointing at the contaminated copy.
        Storage::disk('local')->assertMissing((string) $sermon->transcript_file_path);

        $stamp = $log->fresh()?->processing_metadata?->toArray()['transcript_span_repair'] ?? null;
        self::assertIsArray($stamp);
        self::assertSame('historic_quarantine', $stamp['disk']);
        self::assertSame(strlen(self::CONTAMINATED), $stamp['previous_length']);
        self::assertSame(strlen(self::REPAIRED), $stamp['repaired_length']);
    }

    #[Test]
    public function it_is_resumable_because_a_repaired_run_reports_as_already_repaired(): void
    {
        [$operation] = $this->historicRun();

        $this->artisan('historic-import:repair-sermon-transcript-spans', [
            '--operation' => $operation->operation_id,
            '--execute' => true,
        ])->assertSuccessful();

        $this->artisan('historic-import:repair-sermon-transcript-spans', [
            '--operation' => $operation->operation_id,
            '--execute' => true,
        ])
            ->expectsOutputToContain('already repaired')
            ->expectsOutputToContain('Repaired 0 transcript(s).')
            ->assertSuccessful();
    }

    #[Test]
    public function it_leaves_a_single_span_run_alone(): void
    {
        [$operation, $log, $sermon] = $this->historicRun(segments: [
            ['start_time' => 100.0, 'end_time' => 400.0],
        ]);

        $this->artisan('historic-import:repair-sermon-transcript-spans', [
            '--operation' => $operation->operation_id,
            '--execute' => true,
            '--show-unaffected' => true,
        ])
            ->expectsOutputToContain('no concatenated extraction plan')
            ->expectsOutputToContain('Repaired 0 transcript(s).')
            ->assertSuccessful();

        self::assertSame(self::CONTAMINATED, $this->savedTranscript($sermon));
    }

    #[Test]
    public function it_reports_missing_evidence_as_unresolved_rather_than_guessing(): void
    {
        [$operation, $log, $sermon] = $this->historicRun();
        Storage::disk('local')->delete((string) $log->serviceTranscriptPath());

        $this->artisan('historic-import:repair-sermon-transcript-spans', [
            '--operation' => $operation->operation_id,
            '--execute' => true,
        ])
            ->expectsOutputToContain('the full-service transcript is unavailable')
            ->expectsOutputToContain('Repaired 0 transcript(s).')
            ->assertSuccessful();

        self::assertSame(self::CONTAMINATED, $this->savedTranscript($sermon));
    }

    #[Test]
    public function it_reports_a_missing_saved_transcript_as_unresolved(): void
    {
        [$operation, $log, $sermon] = $this->historicRun();
        Storage::disk('historic_quarantine')->delete((string) $sermon->transcript_file_path);

        $this->artisan('historic-import:repair-sermon-transcript-spans', [
            '--operation' => $operation->operation_id,
            '--execute' => true,
        ])
            ->expectsOutputToContain('the saved transcript is unavailable')
            ->assertSuccessful();
    }

    #[Test]
    public function a_batch_that_will_not_open_is_one_unresolved_row_not_a_dead_pass(): void
    {
        $registry = new class(app(HistoricStagingGuard::class)) extends HistoricStagingContextRegistry
        {
            public function within(HistoricStagingContext $context, \Closure $callback): mixed
            {
                throw new \RuntimeException('storage identity does not match this worker');
            }
        };
        app()->instance(HistoricStagingContextRegistry::class, $registry);

        [$operation] = $this->historicRun(stagingContext: new HistoricStagingContext(
            manifestHash: str_repeat('a', 64),
            planHash: str_repeat('b', 64),
            stagingDisk: 'historic_staging',
            batchRoot: 'historic-batches/unopenable',
            storageIdentity: [
                'driver' => 'local',
                'bucket' => null,
                'root_fingerprint' => str_repeat('c', 64),
                'prefix_fingerprint' => str_repeat('d', 64),
            ],
        ));

        $this->artisan('historic-import:repair-sermon-transcript-spans', [
            '--operation' => $operation->operation_id,
            '--execute' => true,
        ])
            ->expectsOutputToContain('the staging batch could not be opened')
            ->expectsOutputToContain('Repaired 0 transcript(s).')
            ->assertSuccessful();
    }

    #[Test]
    public function it_refuses_to_reanalyse_without_executing_the_repair(): void
    {
        [$operation] = $this->historicRun();
        Queue::fake();

        $this->artisan('historic-import:repair-sermon-transcript-spans', [
            '--operation' => $operation->operation_id,
            '--reanalyse' => true,
        ])
            ->expectsOutputToContain('--reanalyse requires --execute')
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_re_dispatches_analysis_only_for_the_runs_it_repaired(): void
    {
        [$operation, $log] = $this->historicRun();
        [, $untouched] = $this->historicRun(operation: $operation, segments: [
            ['start_time' => 100.0, 'end_time' => 400.0],
        ]);
        Queue::fake();

        $this->artisan('historic-import:repair-sermon-transcript-spans', [
            '--operation' => $operation->operation_id,
            '--execute' => true,
            '--reanalyse' => true,
        ])
            ->expectsOutputToContain('Dispatched 1 re-analysis job(s)')
            ->assertSuccessful();

        Queue::assertPushed(ProcessTranscriptWithAI::class, 1);
    }

    /**
     * The workflow the command itself advises when run without --reanalyse.
     *
     * Selection by text equality cannot express "the text is right but the
     * analysis behind it is not". After a text-only repair the row reports as
     * `already repaired`, so the follow-up invocation the warning recommends
     * dispatches nothing at all, and the sermon keeps analysis derived from the
     * contaminated transcript with nothing on the record saying so.
     */
    #[Test]
    public function a_text_only_repair_still_owes_analysis_on_a_later_invocation(): void
    {
        [$operation] = $this->historicRun();

        $this->artisan('historic-import:repair-sermon-transcript-spans', [
            '--operation' => $operation->operation_id,
            '--execute' => true,
        ])->assertSuccessful();

        Queue::fake();

        $this->artisan('historic-import:repair-sermon-transcript-spans', [
            '--operation' => $operation->operation_id,
            '--execute' => true,
            '--reanalyse' => true,
        ])
            ->expectsOutputToContain('Dispatched 1 re-analysis job(s)')
            ->assertSuccessful();

        Queue::assertPushed(ProcessTranscriptWithAI::class, 1);
    }

    /**
     * The same gap reached by crash rather than by choice: the text is written
     * and the process dies before the dispatch loop. Nothing distinguishes that
     * row from one whose analysis genuinely completed, so re-running the command
     * must still owe the analysis.
     */
    #[Test]
    public function analysis_owed_survives_a_crash_between_writing_text_and_dispatching(): void
    {
        [$operation, $log, $sermon] = $this->historicRun();

        // The repair's own write, with no dispatch after it.
        app(HistoricSermonTranscriptSpanRepair::class)->apply(
            app(HistoricSermonTranscriptSpanRepair::class)->inspect([$log]),
        );

        self::assertSame(self::REPAIRED, $this->savedTranscript($sermon->fresh()));

        Queue::fake();

        $this->artisan('historic-import:repair-sermon-transcript-spans', [
            '--operation' => $operation->operation_id,
            '--execute' => true,
            '--reanalyse' => true,
        ])->assertSuccessful();

        Queue::assertPushed(ProcessTranscriptWithAI::class, 1);
    }

    /**
     * The other half of "owed": it has to stop being owed.
     *
     * Freshness bound to the transcript's content is what lets a retry
     * establish that the input has not changed, so an unchanged run is never
     * charged for a second analysis. Without that, "dispatch whatever is owed"
     * would simply re-charge the whole repaired set on every invocation.
     */
    #[Test]
    public function analysis_is_no_longer_owed_once_it_has_consumed_the_repaired_text(): void
    {
        [$operation, $log, $sermon] = $this->historicRun();

        $this->artisan('historic-import:repair-sermon-transcript-spans', [
            '--operation' => $operation->operation_id,
            '--execute' => true,
        ])->assertSuccessful();

        self::assertTrue($log->fresh()->analysisIsOwed());

        // What ProcessTranscriptWithAI records on success, from the text it read.
        $log->fresh()->recordAnalysedTranscript(
            MediaProcessingLog::hashTranscriptContent($this->savedTranscript($sermon->fresh())),
        );

        self::assertFalse($log->fresh()->analysisIsOwed());

        Queue::fake();

        $this->artisan('historic-import:repair-sermon-transcript-spans', [
            '--operation' => $operation->operation_id,
            '--execute' => true,
            '--reanalyse' => true,
        ])
            ->expectsOutputToContain('Dispatched 0 re-analysis job(s)')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /**
     * A run nobody has repaired is not owed anything. The corpus completed long
     * before any of this was recorded, so silence on both sides must read as
     * "unknown", never as "stale" — otherwise this command becomes a way to
     * re-analyse four hundred sermons by accident.
     */
    #[Test]
    public function a_run_with_no_recorded_transcript_change_is_never_owed(): void
    {
        [, $log] = $this->historicRun();

        self::assertFalse($log->fresh()->analysisIsOwed());
    }

    #[Test]
    public function it_opens_the_runs_own_staging_context_before_reading_its_artifacts(): void
    {
        $context = new HistoricStagingContext(
            manifestHash: str_repeat('a', 64),
            planHash: str_repeat('b', 64),
            stagingDisk: 'historic_staging',
            batchRoot: 'historic-batches/span-repair',
            storageIdentity: [
                'driver' => 'local',
                'bucket' => null,
                'root_fingerprint' => str_repeat('c', 64),
                'prefix_fingerprint' => str_repeat('d', 64),
            ],
        );

        $observed = [];
        $registry = new class(app(HistoricStagingGuard::class), $observed) extends HistoricStagingContextRegistry
        {
            /** @param array<int, string> $observed */
            public function __construct(HistoricStagingGuard $guard, public array &$observed)
            {
                parent::__construct($guard);
            }

            public function within(HistoricStagingContext $context, \Closure $callback): mixed
            {
                $this->observed[] = $context->batchRoot;

                return $callback();
            }
        };
        app()->instance(HistoricStagingContextRegistry::class, $registry);

        [$operation] = $this->historicRun(stagingContext: $context);

        $this->artisan('historic-import:repair-sermon-transcript-spans', [
            '--operation' => $operation->operation_id,
            '--execute' => true,
        ])->assertSuccessful();

        // Once to inspect, once to write: a historic artifact key resolves only
        // while its own batch is active.
        self::assertSame(['historic-batches/span-repair', 'historic-batches/span-repair'], $registry->observed);
    }

    /**
     * @param  list<array{start_time: float, end_time: float}>|null  $segments
     * @return array{0: HistoricImportOperation, 1: MediaProcessingLog, 2: Sermon}
     */
    private function historicRun(
        ?HistoricImportOperation $operation = null,
        ?array $segments = null,
        ?HistoricStagingContext $stagingContext = null,
    ): array {
        $operation ??= $this->createHistoricImportOperation();
        $segments ??= [
            ['start_time' => 100.0, 'end_time' => 200.0],
            ['start_time' => 400.0, 'end_time' => 500.0],
        ];

        $historicImport = ['operation_id' => $operation->operation_id];

        if ($stagingContext instanceof HistoricStagingContext) {
            $historicImport['staging_context'] = $stagingContext->toArray();
        }

        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'historic_import_operation_id' => $operation->id,
            'sermon_start_time' => $segments[0]['start_time'],
            'sermon_end_time' => $segments[count($segments) - 1]['end_time'],
            'processing_metadata' => [
                'historic_import' => $historicImport,
                'sermon_extraction_plan' => ['segments' => $segments],
            ],
        ]);

        $sermon = Sermon::factory()->fromLivestream()->create([
            'livestream_processing_id' => $log->processing_id,
            'historic_import_operation_id' => $operation->id,
            'publication_state' => SermonPublicationState::Quarantined,
            'asset_disk' => 'historic_quarantine',
        ]);
        $log->forceFill(['sermon_id' => $sermon->id])->save();

        $transcriptPath = 'transcripts/sermon_'.$sermon->id.'.md';
        $sermon->forceFill(['transcript_file_path' => $transcriptPath])->save();
        Storage::disk('historic_quarantine')->put($transcriptPath, self::CONTAMINATED);

        $serviceTranscriptPath = 'service-transcripts/2024-10-06/morning-'.$log->processing_id.'.normalized.json';
        Storage::disk('local')->put($serviceTranscriptPath, json_encode(ChurchServiceTranscript::fromCues([
            ['start' => 100.0, 'end' => 200.0, 'text' => 'The preached reading.'],
            ['start' => 250.0, 'end' => 350.0, 'text' => 'An intervening hymn, sung twice.'],
            ['start' => 400.0, 'end' => 500.0, 'text' => 'The sermon itself.'],
        ], 600.0, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));
        $log->putServiceTranscriptPath($serviceTranscriptPath);

        return [$operation, $log->fresh(), $sermon->fresh()];
    }

    private function savedTranscript(Sermon $sermon): string
    {
        return (string) Storage::disk('historic_quarantine')->get((string) $sermon->transcript_file_path);
    }
}
