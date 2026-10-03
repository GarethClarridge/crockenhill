<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\RetranscribeForCorpusRerun;
use App\Data\HistoricStagingContext;
use App\Jobs\AssessSermonVideoQuality;
use App\Jobs\AwaitHistoricSermonVideoStorage;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\CreateSermonTranscriptFromService;
use App\Jobs\EnhanceAudio;
use App\Jobs\ExtractSermon;
use App\Jobs\TranscribeOutputEdges;
use App\Jobs\GenerateThumbnail;
use App\Jobs\IdentifySpeaker;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\PromoteHistoricAssets;
use App\Jobs\SendCompletionNotification;
use App\Jobs\SubmitToProcessing;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricRerunState;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Support\CodeRevision;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class ExtractForCorpusRerunCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    private const SOURCE = 'livestream/temp/source.mp4';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Config::set('media-processing.storage.temp_disk', 'local');
        Config::set('media-processing.storage.transcript_disk', 'local');
        Config::set('media-processing.storage.sermon_disk', 'local');

        $registry = Mockery::mock(HistoricStagingContextRegistry::class);
        $registry->shouldReceive('within')
            ->andReturnUsing(static fn (HistoricStagingContext $context, Closure $callback): mixed => $callback());
        $this->app->instance(HistoricStagingContextRegistry::class, $registry);

        $this->directory = 'rerun-extract-test-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists(storage_path('app/private/'.$this->directory));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/private/'.$this->directory));

        parent::tearDown();
    }

    #[Test]
    public function it_is_a_dry_run_by_default(): void
    {
        Bus::fake();
        $run = $this->roundedRun();

        $this->artisan('historic-import:rerun-extract', ['snapshot' => $this->snapshotPath()])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('ready for extraction')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
        self::assertTrue($run->fresh()?->hasDeferredCorpusRerunMedia());
    }

    #[Test]
    public function it_cuts_a_finished_rounds_media_from_extraction(): void
    {
        Bus::fake();
        $run = $this->roundedRun();

        $this->artisan('historic-import:rerun-extract', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('dispatched from extraction')
            ->assertSuccessful();

        Bus::assertChained([
            TranscribeOutputEdges::class,
            ExtractSermon::class,
            SubmitToProcessing::class,
            EnhanceAudio::class,
            IdentifySpeaker::class,
            CreateSermonTranscriptFromService::class,
            ProcessTranscriptWithAI::class,
            AwaitHistoricSermonVideoStorage::class,
            AssessSermonVideoQuality::class,
            GenerateThumbnail::class,
            PrepareSectionPublicationCandidates::class,
            SendCompletionNotification::class,
            PromoteHistoricAssets::class,
            CleanupTemporaryFiles::class,
        ]);

        $run = $run->fresh();
        $stamps = $run?->corpusRerunStamps() ?? [];

        self::assertSame('extracted', $stamps[0]['media']);
        self::assertFalse($run?->hasDeferredCorpusRerunMedia());
        self::assertTrue($run?->isReExtraction());
    }

    #[Test]
    public function it_refuses_a_run_already_extracted_on_this_commit(): void
    {
        Bus::fake();
        $run = $this->roundedRun();
        $run->amendLatestCorpusRerunStamp(['media' => 'extracted', 'extraction_dispatched_at' => '2026-09-24T20:00:00+00:00']);

        $this->artisan('historic-import:rerun-extract', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('media already extracted on this commit')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    /**
     * Media is only ever cut from a structure the running commit detected.
     */
    #[Test]
    public function it_refuses_a_run_whose_round_ran_on_another_commit(): void
    {
        Bus::fake();
        $run = $this->completedRun();
        $this->snapshot([$run->id]);
        $run->putCorpusRerunStamp(['git_commit' => str_repeat('0', 40), 'media' => 'deferred', 'media_recorded_at' => '2026-09-24T20:00:00+00:00']);

        $this->artisan('historic-import:rerun-extract', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('no detection round on this code')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    /**
     * The command's commit says what should have run; only the worker that finished the round
     * can say what did. A worker booted before the freeze runs the old detectors.
     */
    #[Test]
    public function it_refuses_a_round_a_worker_finished_on_older_code(): void
    {
        Bus::fake();
        $run = $this->roundedRun(stampOverrides: ['worker_commit' => str_repeat('0', 40)]);

        $this->artisan('historic-import:rerun-extract', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('worker code from '.str_repeat('0', 40))
            ->assertSuccessful();

        Bus::assertNothingDispatched();
        self::assertSame('deferred', $run->refresh()->corpusRerunStamps()[0]['media']);
    }

    /**
     * A plan commit after the round restarts the workers on a new commit of the same code: the
     * revisions agree, so the round is cut rather than stranded.
     */
    #[Test]
    public function it_cuts_a_round_whose_workers_ran_the_same_code_on_another_commit(): void
    {
        Bus::fake();
        $this->roundedRun(stampOverrides: ['worker_commit' => str_repeat('0', 40), 'worker_code_revision' => CodeRevision::current()]);

        $this->artisan('historic-import:rerun-extract', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('dispatched from extraction')
            ->assertSuccessful();
    }

    #[Test]
    public function it_refuses_a_round_whose_workers_ran_other_code_on_the_same_commit(): void
    {
        Bus::fake();
        $this->roundedRun(stampOverrides: ['worker_code_revision' => str_repeat('0', 64)]);

        $this->artisan('historic-import:rerun-extract', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('restart the workers and re-detect')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_refuses_a_round_that_recorded_no_worker_code(): void
    {
        Bus::fake();
        $this->roundedRun(stampOverrides: ['worker_commit' => null]);

        $this->artisan('historic-import:rerun-extract', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('worker code from an unrecorded commit')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_refuses_a_round_that_has_not_finished(): void
    {
        Bus::fake();
        $run = $this->roundedRun(recorded: false);

        $this->artisan('historic-import:rerun-extract', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('detection round has not finished')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
        self::assertTrue($run->fresh()?->hasDeferredCorpusRerunMedia());
    }

    /**
     * A Tier A round wrote new text but detected nothing; cutting now would cut the sections the
     * old text was detected on.
     */
    #[Test]
    public function it_refuses_a_run_whose_latest_round_on_this_commit_only_transcribed(): void
    {
        Bus::fake();
        $run = $this->roundedRun();
        $commit = $run->corpusRerunStamps()[0]['git_commit'];
        $run->putCorpusRerunStamp([
            'grounds' => 'corpus_rerun',
            'tier' => RetranscribeForCorpusRerun::TIER,
            'detection' => RetranscribeForCorpusRerun::DETECTION_NONE,
            'git_commit' => $commit,
            'dispatched_at' => '2026-10-01T09:00:00+00:00',
            'transcribed_at' => '2026-10-01T09:05:00+00:00',
            'worker_commit' => $commit,
        ]);

        $this->artisan('historic-import:rerun-extract', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('re-transcribed on this commit but not re-detected')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    /**
     * A transcription round leaves the sections, and the media they describe, as the detection
     * round before it did, so the snapshot and diff still read that round's deferred plan.
     */
    #[Test]
    public function a_transcription_round_keeps_the_previous_detection_rounds_deferred_media(): void
    {
        $run = $this->roundedRun(stampOverrides: ['deferred_extraction_plan' => ['segments' => [['start_time' => 700.0, 'end_time' => 2500.0]]]]);
        $before = app(HistoricRerunState::class)->capture($run);

        $run->putCorpusRerunStamp([
            'grounds' => 'corpus_rerun',
            'tier' => RetranscribeForCorpusRerun::TIER,
            'detection' => RetranscribeForCorpusRerun::DETECTION_NONE,
            'git_commit' => 'a-later-commit',
            'dispatched_at' => '2026-10-01T09:00:00+00:00',
        ]);
        $run->refresh();

        self::assertTrue($run->hasDeferredCorpusRerunMedia());
        self::assertSame($before['sermon_span'], app(HistoricRerunState::class)->capture($run)['sermon_span']);
        self::assertSame(['start' => 700.0, 'end' => 2500.0], $before['sermon_span']);
    }

    #[Test]
    public function it_refuses_when_the_staged_source_is_not_the_recorded_size(): void
    {
        Bus::fake();
        $run = $this->roundedRun();
        Storage::disk('local')->put((string) $run->source_file_path, 'a different encode');

        $this->artisan('historic-import:rerun-extract', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('staged source is not the recorded size')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
        self::assertTrue($run->fresh()?->hasDeferredCorpusRerunMedia());
    }

    #[Test]
    public function it_accepts_a_staged_source_of_the_recorded_size_without_hashing_it(): void
    {
        // Every source was hash-checked as it was staged; re-reading 10 GB a tier bought nothing.
        Bus::fake();
        $run = $this->roundedRun();
        $run->forceFill(['file_hash' => str_repeat('0', 64)])->save();

        $this->artisan('historic-import:rerun-extract', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('dispatched from extraction')
            ->assertSuccessful();
    }

    #[Test]
    public function it_refuses_a_concatenated_run_until_the_concatenation_gate_rebuilds_it(): void
    {
        // Runs 973 and 1014: the original join is still staged, but nothing recorded its hash.
        Bus::fake();
        $run = $this->concatenatedRun();
        $this->roundedStamp($run);

        $this->artisan('historic-import:rerun-extract', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('concatenated source has not been rebuilt')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_dispatches_a_concatenated_run_whose_rebuild_matches_the_gate_stamp(): void
    {
        // The rebuild's container bytes differ from the original join's `file_hash` (run 950);
        // the gate's stamp is what the staged file must hash to.
        Bus::fake();
        $rebuilt = 'the same packets in another container';
        $run = $this->concatenatedRun();
        Storage::disk('local')->put((string) $run->source_file_path, $rebuilt);
        $run->forceFill(['file_size' => strlen($rebuilt)])->save();
        $run->writeProcessingMetadata(static fn (array $metadata): array => [
            ...$metadata,
            'concatenated_source_restage' => ['sha256' => hash('sha256', $rebuilt), 'duration' => 3001.531, 'parts' => 7],
        ]);
        $this->roundedStamp($run);

        $this->artisan('historic-import:rerun-extract', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('dispatched from extraction')
            ->assertSuccessful();
    }

    /**
     * A member whose detection round on the running commit has finished and deferred its media.
     */
    /**
     * @param  array<string, mixed>  $stampOverrides
     */
    private function roundedRun(bool $recorded = true, array $stampOverrides = []): MediaProcessingLog
    {
        $run = $this->completedRun();
        $this->roundedStamp($run, $recorded, $stampOverrides);

        return $run->refresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function roundedStamp(MediaProcessingLog $run, bool $recorded = true, array $overrides = []): void
    {
        $this->snapshot([$run->id]);
        $commit = json_decode((string) file_get_contents(storage_path('app/private/'.$this->snapshotPath())), true)['git_commit'];

        $run->putCorpusRerunStamp([
            'grounds' => 'corpus_rerun',
            'git_commit' => $commit,
            'media' => 'deferred',
            'dispatched_at' => '2026-09-24T19:00:00+00:00',
            ...($recorded ? ['media_recorded_at' => '2026-09-24T19:30:00+00:00', 'worker_commit' => $commit] : []),
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $metadata
     */
    private function completedRun(array $attributes = [], array $metadata = []): MediaProcessingLog
    {
        $operation = $this->createHistoricImportOperation();

        // Each run its own recording: runs sharing a hash would share a dedup key on reopening.
        $bytes = 'source bytes '.$operation->operation_id;
        Storage::disk('local')->put(self::SOURCE.'.'.$operation->id, $bytes);

        return MediaProcessingLog::factory()->livestream()->completed()->create([
            'historic_import_operation_id' => $operation->id,
            'source_file_path' => self::SOURCE.'.'.$operation->id,
            'file_hash' => hash('sha256', $bytes),
            'file_size' => strlen($bytes),
            'sermon_start_time' => 600.0,
            'sermon_end_time' => 2400.0,
            'processing_metadata' => [
                'historic_import' => [
                    'operation_id' => $operation->operation_id,
                    'staging_context' => $this->stagingContext()->toArray(),
                ],
                'sermon_extraction_plan' => ['segments' => [['start_time' => 600.0, 'end_time' => 2400.0]]],
                'service_structure' => ['sections' => []],
                ...$metadata,
            ],
            ...$attributes,
        ]);
    }

    private function concatenatedRun(): MediaProcessingLog
    {
        $run = $this->completedRun();
        $run->writeProcessingMetadata(static function (array $metadata): array {
            $metadata['historic_import']['concatenation'] = 'lossless';

            return $metadata;
        });

        return $run->refresh();
    }

    private function stagingContext(): HistoricStagingContext
    {
        return new HistoricStagingContext(
            manifestHash: str_repeat('a', 64),
            planHash: str_repeat('b', 64),
            stagingDisk: 'historic_staging',
            batchRoot: 'historic-batches/corpus-rerun',
            storageIdentity: [
                'driver' => 'local',
                'bucket' => null,
                'root_fingerprint' => str_repeat('c', 64),
                'prefix_fingerprint' => str_repeat('d', 64),
            ],
        );
    }

    /**
     * @param  list<int>  $runIds
     */
    private function snapshot(array $runIds): void
    {
        $this->artisan('historic-import:rerun-snapshot', ['runs' => $runIds, '--output' => $this->snapshotPath()])
            ->assertSuccessful();
    }

    /**
     * @param  Closure(array<string, mixed>): array<string, mixed>  $change
     */
    private function rewriteSnapshot(Closure $change): void
    {
        $path = storage_path('app/private/'.$this->snapshotPath());
        file_put_contents($path, json_encode($change(json_decode((string) file_get_contents($path), true))));
    }

    private function snapshotPath(): string
    {
        return $this->directory.'/before.json';
    }
}
