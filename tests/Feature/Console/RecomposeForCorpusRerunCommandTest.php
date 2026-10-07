<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\RedetectForCorpusRerun;
use App\Data\HistoricStagingContext;
use App\Enums\ProcessingStatus;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\DetectServiceStructure;
use App\Jobs\ExtendSongsOverOwnLyrics;
use App\Jobs\MatchSongsFromTranscript;
use App\Jobs\MergeSongContinuations;
use App\Jobs\ProjectLivestreamServiceStructure;
use App\Jobs\PromoteHistoricAssets;
use App\Jobs\RecordDeferredCorpusRerunMedia;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\Structure\EnsembleReviewGate;
use App\Services\HistoricMedia\HistoricRerunSnapshot;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Support\PrivateEvidenceFile;
use App\Support\RepositoryCommit;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\Support\AudioTimelineFixture;
use Tests\TestCase;

class RecomposeForCorpusRerunCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    private const SOURCE = 'livestream/temp/source.mp4';

    private string $directory;

    private bool $inputIsCurrent = true;

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

        // Whether banked draws still fit their run is the gate's own, tested in EnsembleReviewGateTest.
        $gate = Mockery::mock(EnsembleReviewGate::class);
        $gate->shouldReceive('inputIsCurrent')->andReturnUsing(fn (): bool => $this->inputIsCurrent);
        $this->app->instance(EnsembleReviewGate::class, $gate);

        $this->directory = 'rerun-recompose-test-'.bin2hex(random_bytes(6));
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
        $run = $this->completedRun();
        $this->snapshot([$run->id]);
        $this->roundHeldForReview($run);

        $this->artisan('historic-import:rerun-recompose', ['snapshot' => $this->snapshotPath()])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('ready to recompose banked draws attempt-1')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
        self::assertCount(1, $run->fresh()?->corpusRerunStamps() ?? []);
        self::assertArrayNotHasKey(DetectServiceStructure::RECOMPOSE_KEY, $run->fresh()?->processing_metadata?->toArray() ?? []);
    }

    /**
     * The operator answered the round's questions on the draws it made; a second detection on
     * the commit is refused, and would cut draws nobody reviewed if it were not.
     */
    #[Test]
    public function it_continues_a_round_held_for_questions_on_the_same_commit_from_its_own_draws(): void
    {
        Bus::fake();
        $run = $this->completedRun();
        $this->snapshot([$run->id]);
        $this->roundHeldForReview($run);

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath()])
            ->expectsOutputToContain('already re-run on this commit')
            ->assertSuccessful();

        $this->artisan('historic-import:rerun-recompose', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('banked draws recomposed')
            ->assertSuccessful();

        $stamps = $run->fresh()?->corpusRerunStamps() ?? [];
        self::assertCount(2, $stamps);
        self::assertSame('recompose', $stamps[1]['detection']);
        self::assertSame('attempt-1', $stamps[1]['recomposed_attempt_id']);
        self::assertSame('deferred', $stamps[1]['media']);
        self::assertSame('attempt-1', $run->fresh()?->processing_metadata?->toArray()[DetectServiceStructure::RECOMPOSE_KEY]['attempt_id']);
        Bus::assertChained([
            DetectServiceStructure::class,
            ProjectLivestreamServiceStructure::class,
            MatchSongsFromTranscript::class,
            MergeSongContinuations::class,
            ExtendSongsOverOwnLyrics::class,
            ProjectLivestreamServiceStructure::class,
            RecordDeferredCorpusRerunMedia::class,
            PromoteHistoricAssets::class,
            CleanupTemporaryFiles::class,
        ]);
    }

    /** After a rule is adopted the run's round is on an older commit; recomposing moves it on. */
    #[Test]
    public function it_brings_a_run_detected_on_an_older_commit_onto_this_one_without_drawing(): void
    {
        Bus::fake();
        $run = $this->completedRun(metadata: [
            RedetectForCorpusRerun::STAMP_KEY => [['grounds' => 'corpus_rerun', 'git_commit' => str_repeat('e', 40), 'media' => 'deferred', 'media_recorded_at' => '2026-10-01T10:00:00+00:00']],
            'service_structure_ensemble' => [['attempt_id' => 'attempt-older-commit']],
        ]);
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-recompose', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('banked draws recomposed')
            ->assertSuccessful();

        self::assertSame('attempt-older-commit', $run->fresh()?->corpusRerunStamps()[1]['recomposed_attempt_id'] ?? null);
    }

    /**
     * Run 1250: an earlier round's extraction parked it for composition review, a question the
     * composition re-derives under current rules. Recomposing it is the next round's own work: if
     * the question remains, extraction parks it again.
     */
    #[Test]
    public function a_run_an_earlier_round_parked_for_composition_review_can_be_recomposed(): void
    {
        $run = $this->parkedForCompositionReview(byARound: true);

        $this->artisan('historic-import:rerun-recompose', ['snapshot' => $this->snapshotPath(), 'runs' => [$run->id]])
            ->expectsOutputToContain('ready to recompose banked draws attempt-1')
            ->assertSuccessful();
    }

    /** The same park from routine processing is not a round's to override. */
    #[Test]
    public function a_run_routine_processing_parked_for_composition_review_is_refused(): void
    {
        $run = $this->parkedForCompositionReview(byARound: false);

        $this->artisan('historic-import:rerun-recompose', ['snapshot' => $this->snapshotPath(), 'runs' => [$run->id]])
            ->expectsOutputToContain('run is failed, not completed')
            ->assertSuccessful();
    }

    private function parkedForCompositionReview(bool $byARound): MediaProcessingLog
    {
        $run = $this->completedRun();
        $metadata = $run->processing_metadata?->toArray() ?? [];
        $metadata['service_structure_ensemble'] = [['attempt_id' => 'attempt-1']];
        $metadata['manual_review'] = ['status' => 'required', 'reason_code' => 'sermon_composition_review'];
        if ($byARound) {
            $metadata[RedetectForCorpusRerun::STAMP_KEY] = [['grounds' => 'corpus_rerun', 'git_commit' => 'an-earlier-commit',
                'snapshot_file_sha256' => 'an-earlier-snapshot', 'media' => 'deferred', 'dispatched_at' => '2026-10-05T10:00:00+00:00']];
        }
        $run->forceFill(['status' => ProcessingStatus::Failed, 'current_step' => 'manual_review_required', 'processing_metadata' => $metadata])->save();
        $this->snapshot([$run->id]);

        return $run;
    }

    #[Test]
    public function it_refuses_a_run_whose_banked_input_no_longer_matches(): void
    {
        $run = $this->completedRun();
        $this->snapshot([$run->id]);
        $this->roundHeldForReview($run);
        $this->inputIsCurrent = false;

        $this->artisan('historic-import:rerun-recompose', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('no longer matches the run; re-detect it')
            ->assertSuccessful();

        self::assertCount(1, $run->fresh()?->corpusRerunStamps() ?? []);
    }

    #[Test]
    public function it_refuses_a_run_with_no_banked_draws(): void
    {
        $run = $this->completedRun();
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-recompose', ['snapshot' => $this->snapshotPath()])
            ->expectsOutputToContain('no banked ensemble draws')
            ->assertSuccessful();
    }

    /** Its media is cut on this commit: recomposing would not reach the clips. */
    #[Test]
    public function it_refuses_a_round_whose_media_this_commit_already_cut(): void
    {
        $run = $this->completedRun();
        $this->snapshot([$run->id]);
        $this->roundHeldForReview($run, media: 'extracted', status: ProcessingStatus::Completed);

        $this->artisan('historic-import:rerun-recompose', ['snapshot' => $this->snapshotPath()])
            ->expectsOutputToContain('already re-run on this commit')
            ->assertSuccessful();
    }

    #[Test]
    public function it_refuses_an_excluded_run_whose_round_it_would_continue(): void
    {
        $run = $this->completedRun();
        $this->snapshot([$run->id]);
        $this->roundHeldForReview($run, extra: ['exclusion' => ['reason' => MediaProcessingLog::EXCLUSION_REASON_PRIVATE_OCCASION]]);

        $this->artisan('historic-import:rerun-recompose', ['snapshot' => $this->snapshotPath()])
            ->expectsOutputToContain('run is excluded')
            ->assertSuccessful();
    }

    /**
     * Stamp the run as a round on this commit and snapshot did: draws banked and, unless told
     * otherwise, held for the ensemble's questions.
     *
     * @param  array<string, mixed>  $extra
     */
    private function roundHeldForReview(
        MediaProcessingLog $run,
        string $media = 'deferred',
        ProcessingStatus $status = ProcessingStatus::Failed,
        array $extra = [],
    ): void {
        $snapshot = HistoricRerunSnapshot::fromFile(PrivateEvidenceFile::resolve($this->snapshotPath(), 'The re-run snapshot'));
        $metadata = $run->processing_metadata?->toArray() ?? [];
        $metadata[RedetectForCorpusRerun::STAMP_KEY] = [[
            'grounds' => 'corpus_rerun',
            'git_commit' => RepositoryCommit::current(),
            'snapshot_file_sha256' => $snapshot->fileSha256,
            'media' => $media,
            'dispatched_at' => '2026-10-02T10:00:00+00:00',
        ]];
        $metadata['service_structure_ensemble'] = [['attempt_id' => 'attempt-1']];

        if ($status === ProcessingStatus::Failed) {
            $metadata['manual_review'] = ['status' => 'required', 'reason_code' => 'service_structure_ensemble_review'];
        }

        $run->forceFill([
            'status' => $status,
            'current_step' => $status === ProcessingStatus::Failed ? 'manual_review_required' : $run->current_step,
            'processing_metadata' => [...$metadata, ...$extra],
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $metadata
     */
    private function completedRun(array $attributes = [], array $metadata = []): MediaProcessingLog
    {
        $operation = $this->createHistoricImportOperation();
        $bytes = 'source bytes '.$operation->operation_id;
        Storage::disk('local')->put(self::SOURCE.'.'.$operation->id, $bytes);

        return MediaProcessingLog::factory()->livestream()->completed()->create([
            'historic_import_operation_id' => $operation->id,
            'source_file_path' => self::SOURCE.'.'.$operation->id,
            'file_hash' => hash('sha256', $bytes),
            'sermon_start_time' => 600.0,
            'sermon_end_time' => 2400.0,
            'audio_timeline_path' => AudioTimelineFixture::put('local', 'temp/audio_timeline_'.$operation->id.'.classes.json'),
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

    /** @param  list<int>  $runIds */
    private function snapshot(array $runIds): void
    {
        $this->artisan('historic-import:rerun-snapshot', ['runs' => $runIds, '--output' => $this->snapshotPath()])
            ->assertSuccessful();
    }

    private function snapshotPath(): string
    {
        return $this->directory.'/before.json';
    }
}
