<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\RedetectForCorpusRerun;
use App\Data\HistoricStagingContext;
use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\DetectServiceStructure;
use App\Jobs\ExtendSongsOverOwnLyrics;
use App\Jobs\MatchSongsFromTranscript;
use App\Jobs\MergeSongContinuations;
use App\Jobs\ProjectLivestreamServiceStructure;
use App\Jobs\PromoteHistoricAssets;
use App\Jobs\RecordDeferredCorpusRerunMedia;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
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

class RedetectForCorpusRerunCommandTest extends TestCase
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

        $this->directory = 'rerun-redetect-test-'.bin2hex(random_bytes(6));
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

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath()])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('ready for re-detection')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
        self::assertSame([], $run->fresh()?->corpusRerunStamps());
        self::assertSame(ProcessingStatus::Completed, $run->fresh()?->status);
    }

    #[Test]
    public function it_stamps_and_dispatches_a_member_from_structure_detection(): void
    {
        Bus::fake();
        $run = $this->completedRun();
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('dispatched from structure detection')
            ->assertSuccessful();

        $stamps = $run->fresh()?->corpusRerunStamps() ?? [];

        self::assertCount(1, $stamps);
        self::assertSame('corpus_rerun', $stamps[0]['grounds']);
        self::assertSame('deferred', $stamps[0]['media']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{40}\z/', $stamps[0]['git_commit']);
        self::assertTrue($run->fresh()?->hasDeferredCorpusRerunMedia());
    }

    /**
     * A detection round is repeated after every detector fix, so it cuts nothing: the chain
     * stops before extraction, records what would be cut, and completes the run (plan §4.0).
     */
    #[Test]
    public function it_dispatches_a_detection_round_that_cuts_no_media(): void
    {
        Bus::fake();
        $run = $this->completedRun();
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->assertSuccessful();

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

        // Nothing is re-cut, so the run is not marked as re-cutting published media.
        self::assertFalse($run->fresh()?->isReExtraction());
    }

    /**
     * The stamp is what lets a batch resume after an interruption, and what keeps a canary
     * run from being re-run by the batch that follows it on the same commit.
     */
    #[Test]
    public function it_refuses_a_run_already_re_run_on_this_commit(): void
    {
        Bus::fake();
        $run = $this->completedRun();
        $this->snapshot([$run->id]);
        $commit = json_decode((string) file_get_contents(storage_path('app/private/'.$this->snapshotPath())), true)['git_commit'];
        $run->putCorpusRerunStamp(['git_commit' => $commit, 'dispatched_at' => '2026-09-24T12:00:00+00:00']);

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('already re-run on this commit')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_refuses_a_snapshot_taken_on_other_code(): void
    {
        Bus::fake();
        $run = $this->completedRun();
        $this->snapshot([$run->id]);
        $this->rewriteSnapshot(static fn (array $data): array => [...$data, 'git_commit' => str_repeat('0', 40), 'code_revision' => str_repeat('0', 64)]);

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('take a new snapshot on the frozen code')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    /** A snapshot written before code revisions binds its commit, as it always did. */
    #[Test]
    public function it_refuses_a_snapshot_without_a_code_revision_taken_on_another_commit(): void
    {
        Bus::fake();
        $run = $this->completedRun();
        $this->snapshot([$run->id]);
        $this->rewriteSnapshot(static function (array $data): array {
            unset($data['code_revision']);

            return [...$data, 'git_commit' => str_repeat('0', 40)];
        });

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('take a new snapshot on the frozen code')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    /**
     * A commit that changes only the plan leaves the code, and so the snapshot, as it was: the
     * batch's rounds are not stranded by writing down what they found.
     */
    #[Test]
    public function it_accepts_a_snapshot_taken_on_another_commit_of_the_same_code(): void
    {
        Bus::fake();
        $run = $this->completedRun();
        $this->snapshot([$run->id]);
        $this->rewriteSnapshot(static fn (array $data): array => [...$data, 'git_commit' => str_repeat('0', 40)]);

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('dispatched from structure detection')
            ->assertSuccessful();

        self::assertSame(str_repeat('0', 40), $run->fresh()?->corpusRerunStamps()[0]['git_commit'] ?? null);
    }

    #[Test]
    public function it_refuses_a_run_that_changed_since_the_snapshot(): void
    {
        Bus::fake();
        $run = $this->completedRun();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Song,
            'start_time' => 100,
            'end_time' => 300,
            'duration' => 200,
        ]);
        $this->snapshot([$run->id]);

        $section->update(['section_type' => ServiceSectionType::Other]);

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('run has changed since the snapshot')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    /**
     * Re-detection reads the recorded timeline and never regenerates it, so a timeline replaced
     * after the snapshot is a change the re-run did not make (music and silence plan §6.6).
     */
    #[Test]
    public function it_refuses_a_run_whose_audio_timeline_was_replaced_since_the_snapshot(): void
    {
        Bus::fake();
        $run = $this->completedRun();
        $this->snapshot([$run->id]);

        Storage::disk('local')->put((string) $run->audio_timeline_path, AudioTimelineFixture::json([[0, 600, 0.9, 0.1]], 2430.0));

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('run has changed since the snapshot')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_refuses_a_run_whose_audio_model_revision_changed_since_the_snapshot(): void
    {
        Bus::fake();
        $run = $this->completedRun();
        $this->snapshot([$run->id]);

        $payload = AudioTimelineFixture::payload([], 2430.0);
        $payload['model_revision'] = str_repeat('9', 40);
        Storage::disk('local')->put((string) $run->audio_timeline_path, (string) json_encode($payload));

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('run has changed since the snapshot')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_refuses_a_run_with_no_audio_timeline(): void
    {
        Bus::fake();
        $run = $this->completedRun(['audio_timeline_path' => null]);
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('run has no readable audio timeline')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_refuses_a_run_whose_audio_timeline_cannot_be_read(): void
    {
        Bus::fake();
        $run = $this->completedRun(['audio_timeline_path' => 'temp/never-written.classes.json']);
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('run has no readable audio timeline')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    /** A snapshot taken before the timeline was captured cannot describe it. */
    #[Test]
    public function it_refuses_a_snapshot_captured_in_the_shape_before_the_audio_timeline(): void
    {
        $run = $this->completedRun();
        $this->snapshot([$run->id]);
        $this->rewriteSnapshot(static fn (array $data): array => [...$data, 'version' => 1]);

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('captured shape version 1; this code captures version 2')
            ->assertFailed();
    }

    #[Test]
    public function it_refuses_runs_outside_the_snapshot(): void
    {
        $run = $this->completedRun();
        $other = $this->completedRun();
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath(), 'runs' => [$other->id]])
            ->expectsOutputToContain('Not in the snapshot: '.$other->id)
            ->assertFailed();
    }

    /**
     * A round reads banked evidence, never the recording, so it does not hash it; the cut
     * checks the source (historic-import:rerun-extract).
     */
    #[Test]
    public function a_detection_round_does_not_read_the_staged_source(): void
    {
        Bus::fake();
        $run = $this->completedRun();
        $this->snapshot([$run->id]);
        Storage::disk('local')->put((string) $run->source_file_path, 'a different encode');

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('dispatched from structure detection')
            ->assertSuccessful();

        self::assertCount(1, $run->fresh()?->corpusRerunStamps() ?? []);
    }

    #[Test]
    public function it_refuses_a_failed_run(): void
    {
        $run = $this->completedRun(['status' => ProcessingStatus::Failed, 'current_step' => 'manual_review_required']);
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath()])
            ->expectsOutputToContain('run is failed, not completed')
            ->assertSuccessful();
    }

    /**
     * 1304, canary 4: a round's detection left §3872's content hold with no song to land on, so
     * the run parked. The park asks the operator to confirm or re-hold before re-running; once
     * the hold is released the run goes back into the re-run, and the round's own reset clears
     * the park.
     */
    #[Test]
    public function it_admits_a_run_a_round_parked_on_an_unplaced_hold_once_the_hold_is_released(): void
    {
        Bus::fake();
        [$run] = $this->parkedRun(holdStillLive: false);
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('dispatched from structure detection')
            ->assertSuccessful();

        self::assertCount(2, $run->fresh()?->corpusRerunStamps() ?? []);
        self::assertArrayNotHasKey('manual_review', $run->fresh()?->processing_metadata?->toArray() ?? []);
    }

    #[Test]
    public function it_refuses_a_parked_run_while_the_unplaced_hold_is_still_live(): void
    {
        [$run, $section] = $this->parkedRun(holdStillLive: true);
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath()])
            ->expectsOutputToContain("run is parked: section {$section->id}'s content hold had nowhere to land; confirm the section to release it")
            ->assertSuccessful();
    }

    /** Only a park the re-run itself caused: an ordinary failed run stays out. */
    #[Test]
    public function it_refuses_a_run_parked_on_an_unplaced_hold_by_something_other_than_a_rerun(): void
    {
        [$run] = $this->parkedRun(holdStillLive: false, stamped: false);
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath()])
            ->expectsOutputToContain('run is failed, not completed')
            ->assertSuccessful();
    }

    /**
     * Canary 9: the four-draw ensemble held 13 of the 16 runs for its questions. Re-detecting
     * them on a new freeze is the round's own work; their earlier bundles stay banked as
     * evidence, so the questions are not lost.
     */
    #[Test]
    public function it_admits_a_run_a_round_held_for_ensemble_review(): void
    {
        Bus::fake();
        $run = $this->heldForEnsembleReview();
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('dispatched from structure detection')
            ->assertSuccessful();

        self::assertCount(2, $run->fresh()?->corpusRerunStamps() ?? []);
    }

    /** Only a hold the re-run itself caused: an ensemble hold from routine processing stays out. */
    #[Test]
    public function it_refuses_a_run_held_for_ensemble_review_by_something_other_than_a_rerun(): void
    {
        $run = $this->heldForEnsembleReview(stamped: false);
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath()])
            ->expectsOutputToContain('run is failed, not completed')
            ->assertSuccessful();
    }

    #[Test]
    public function it_refuses_an_excluded_run(): void
    {
        $run = $this->completedRun(metadata: ['exclusion' => ['reason' => MediaProcessingLog::EXCLUSION_REASON_PRIVATE_OCCASION]]);
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath()])
            ->expectsOutputToContain('run is excluded')
            ->assertSuccessful();
    }

    /**
     * Run 935 was proposed as a canary member on 09-24 while superseded since 08-27; the
     * orchestrator's shared guard is what refuses it.
     */
    #[Test]
    public function it_refuses_a_superseded_run_through_the_shared_guard(): void
    {
        $run = $this->completedRun(['superseded_at' => now()]);
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath()])
            ->expectsOutputToContain('retired or superseded')
            ->assertSuccessful();
    }

    #[Test]
    public function it_dispatches_no_more_than_max_runs_per_invocation(): void
    {
        Bus::fake();
        $first = $this->completedRun();
        $second = $this->completedRun();
        $this->snapshot([$first->id, $second->id]);

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath(), '--max' => 1, '--execute' => true])
            ->expectsOutputToContain('--max=1 reached')
            ->assertSuccessful();

        self::assertCount(1, $first->fresh()?->corpusRerunStamps() ?? []);
        self::assertSame([], $second->fresh()?->corpusRerunStamps());

        // Re-running the command continues the batch: the first is refused as done.
        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath(), '--max' => 1, '--execute' => true])
            ->expectsOutputToContain('already re-run on this commit')
            ->assertSuccessful();

        self::assertCount(1, $second->fresh()?->corpusRerunStamps() ?? []);
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

    /**
     * A run parked the way canary 4 parked 1304: failed at manual review for an unplaced content
     * hold, with the refused proposal naming the section whose hold had nowhere to land.
     *
     * @return array{0: MediaProcessingLog, 1: ServiceSection}
     */
    private function parkedRun(bool $holdStillLive, bool $stamped = true): array
    {
        $run = $this->completedRun(['status' => ProcessingStatus::Failed, 'current_step' => 'manual_review_required']);
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song,
            'start_time' => 261.0,
            'end_time' => 364.6,
            'needs_manual_review' => $holdStillLive,
            'metadata' => $holdStillLive ? ['review_flags' => ['content_defect_hold']] : [],
        ]);

        $metadata = $run->processing_metadata?->toArray() ?? [];
        $metadata['manual_review'] = ['status' => 'required', 'reason_code' => 'unplaced_content_hold'];
        $metadata['service_structure_proposal'] = ['refused_reason' => 'unplaced_content_hold', 'unplaced_content_hold_section_ids' => [$section->id]];

        if ($stamped) {
            $metadata[RedetectForCorpusRerun::STAMP_KEY] = [['grounds' => 'corpus_rerun', 'git_commit' => str_repeat('e', 40), 'media' => 'deferred', 'dispatched_at' => '2026-09-25T10:32:55+00:00']];
        }

        $run->forceFill(['processing_metadata' => $metadata])->save();

        return [$run->fresh() ?? $run, $section];
    }

    private function heldForEnsembleReview(bool $stamped = true): MediaProcessingLog
    {
        $run = $this->completedRun(['status' => ProcessingStatus::Failed, 'current_step' => 'manual_review_required']);
        $metadata = $run->processing_metadata?->toArray() ?? [];
        $metadata['manual_review'] = ['status' => 'required', 'reason_code' => 'service_structure_ensemble_review'];

        if ($stamped) {
            $metadata[RedetectForCorpusRerun::STAMP_KEY] = [['grounds' => 'corpus_rerun', 'git_commit' => str_repeat('e', 40), 'media' => 'deferred', 'dispatched_at' => '2026-09-30T19:12:05+00:00']];
        }

        $run->forceFill(['processing_metadata' => $metadata])->save();

        return $run->fresh() ?? $run;
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
        // Floats keep their fraction, so only the change under test differs from what was captured.
        file_put_contents($path, json_encode($change(json_decode((string) file_get_contents($path), true)), JSON_PRESERVE_ZERO_FRACTION));
    }

    private function snapshotPath(): string
    {
        return $this->directory.'/before.json';
    }
}
