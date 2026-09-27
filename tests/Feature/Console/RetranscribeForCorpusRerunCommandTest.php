<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\HoldSectionForContentReview;
use App\Actions\RetranscribeForCorpusRerun;
use App\Data\ChurchServiceTranscript;
use App\Data\HistoricStagingContext;
use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\AnalyzeSegments;
use App\Jobs\ClassifyServiceAudio;
use App\Jobs\CleanupTemporaryFiles;
use App\Jobs\DetectServiceStructure;
use App\Jobs\ExtendSongsOverOwnLyrics;
use App\Jobs\GenerateRmsLog;
use App\Jobs\MatchSongsFromTranscript;
use App\Jobs\MergeSongContinuations;
use App\Jobs\ProjectLivestreamServiceStructure;
use App\Jobs\PromoteHistoricAssets;
use App\Jobs\RecordDeferredCorpusRerunMedia;
use App\Jobs\TranscribeFullService;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\Media\Audio\ServiceTranscriptRedecoder;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\BatchFake;
use Illuminate\Support\Testing\Fakes\PendingBatchFake;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\Support\AudioTimelineFixture;
use Tests\TestCase;

class RetranscribeForCorpusRerunCommandTest extends TestCase
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

        $this->directory = 'rerun-retranscribe-test-'.bin2hex(random_bytes(6));
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
        $run = $this->heldRun();
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-retranscribe', ['snapshot' => $this->snapshotPath()])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('ready for re-transcription (1 transcript-loss hold)')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
        self::assertSame(ProcessingStatus::Completed, $run->fresh()?->status);
        self::assertSame([], $run->fresh()?->corpusRerunStamps());
    }

    #[Test]
    public function it_reopens_a_held_run_at_full_service_transcription_and_stamps_it(): void
    {
        Bus::fake();
        $run = $this->heldRun();
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-retranscribe', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('dispatched from full-service transcription')
            ->assertSuccessful();

        $fresh = $run->fresh();
        self::assertSame(ProcessingStatus::Processing, $fresh?->status);
        self::assertSame('transcribe_full_service', $fresh?->current_step);

        $stamps = $fresh?->corpusRerunStamps() ?? [];
        self::assertCount(1, $stamps);
        self::assertSame(RetranscribeForCorpusRerun::TIER, $stamps[0]['tier']);
        self::assertSame('corpus_rerun', $stamps[0]['grounds']);
        self::assertSame('deferred', $stamps[0]['media']);
        self::assertCount(1, $stamps[0]['transcript_loss_sections']);
        self::assertNull($stamps[0]['listening_routing_sha256']);
    }

    /**
     * Tier A is a detection round too (operator, 2026-09-24): it transcribes afresh, detects and
     * records what extraction would cut, and Tier C cuts the media once on the frozen commit.
     */
    #[Test]
    public function it_transcribes_afresh_and_stops_before_extraction(): void
    {
        Bus::fake();
        $run = $this->heldRun();
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-retranscribe', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->assertSuccessful();

        // Nothing is re-cut, so the run is not marked as re-cutting published media.
        self::assertFalse($run->fresh()?->isReExtraction());

        $batches = 0;
        Bus::assertBatched(function (PendingBatchFake $batch) use (&$batches): bool {
            $batches++;
            self::assertSame([GenerateRmsLog::class], $batch->jobs->map(fn (object $job): string => $job::class)->all());

            foreach ($batch->thenCallbacks() as $callback) {
                $callback(new BatchFake('batch', '', 1, 0, 0, [], [], CarbonImmutable::now()));
            }

            return true;
        });
        self::assertSame(1, $batches);

        Bus::assertChained([
            AnalyzeSegments::class,
            TranscribeFullService::class,
            ClassifyServiceAudio::class,
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

    /**
     * The 128 runs listening rated "new better" need no hand-written holds: the snapshot freezes
     * their routes from the routing file, read only against the sha256 the operator states.
     */
    #[Test]
    public function it_accepts_a_run_the_listening_routing_rates_new_better(): void
    {
        Bus::fake();
        $run = $this->completedRun();
        $listened = $this->storeTranscript($run, 'The Lord is my shepherd.');
        $sha256 = $this->routing([$run->id => ['new_better', $listened]]);
        $this->snapshot([$run->id], $sha256);

        $this->artisan('historic-import:rerun-retranscribe', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('dispatched from full-service transcription (listening rated the new decode better); media deferred')
            ->assertSuccessful();

        $stamps = $run->fresh()?->corpusRerunStamps() ?? [];
        self::assertCount(1, $stamps);
        self::assertSame([], $stamps[0]['transcript_loss_sections']);
        self::assertSame($sha256, $stamps[0]['listening_routing_sha256']);
    }

    /**
     * 1112 and 1287 were re-transcribed after listening judged their old text: a route, like a
     * hold, describes one transcript and lapses with it.
     */
    #[Test]
    public function a_listening_route_lapses_once_the_run_holds_different_text(): void
    {
        $run = $this->completedRun();
        $listened = $this->storeTranscript($run, 'The Lord is my shepherd.');
        $this->storeTranscript($run, 'The Lord is my shepherd, I shall not want.');
        $sha256 = $this->routing([$run->id => ['new_better', $listened]]);

        $this->assertRefused($run, 'no new_better listening route', $sha256);
    }

    /**
     * Stored-better, mixed and neither runs stay with Tier B; a run the file does not name has
     * no listening grounds at all.
     */
    #[Test]
    public function it_refuses_a_run_the_listening_routing_sends_elsewhere_or_leaves_out(): void
    {
        Bus::fake();
        $storedBetter = $this->completedRun();
        $unrouted = $this->completedRun();
        $sha256 = $this->routing([$storedBetter->id => ['stored_better', $this->storeTranscript($storedBetter, 'Amen.')]]);
        $this->snapshot([$storedBetter->id, $unrouted->id], $sha256);

        $this->artisan('historic-import:rerun-retranscribe', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('no new_better listening route')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    /**
     * Tier B reads the same frozen routes, so running it first cannot stamp a run on this
     * commit that listening sent to Tier A.
     */
    #[Test]
    public function tier_b_leaves_a_run_listening_rated_new_better_to_tier_a(): void
    {
        Bus::fake();
        $run = $this->completedRun();
        $sha256 = $this->routing([$run->id => ['new_better', $this->storeTranscript($run, 'Amen.')]]);
        $this->snapshot([$run->id], $sha256);

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('listening rated the new decode better')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
        self::assertSame([], $run->fresh()?->corpusRerunStamps());
    }

    #[Test]
    public function the_snapshot_refuses_a_routing_file_that_changed_since_its_hash_was_stated(): void
    {
        $run = $this->completedRun();
        $this->routing([$run->id => ['new_better', str_repeat('e', 64)]]);

        $this->artisan('historic-import:rerun-snapshot', [
            'runs' => [$run->id],
            '--output' => $this->snapshotPath(),
            '--routing' => $this->routingPath(),
            '--routing-sha256' => str_repeat('0', 64),
        ])
            ->expectsOutputToContain('not the stated sha256')
            ->assertFailed();
    }

    #[Test]
    public function the_snapshot_needs_a_routing_file_s_stated_hash(): void
    {
        $run = $this->completedRun();
        $this->routing([$run->id => ['new_better', str_repeat('e', 64)]]);

        $this->artisan('historic-import:rerun-snapshot', [
            'runs' => [$run->id],
            '--output' => $this->snapshotPath(),
            '--routing' => $this->routingPath(),
        ])
            ->expectsOutputToContain('--routing-sha256')
            ->assertFailed();
    }

    /**
     * Tier A is for text known to be wrong where the audio is not. A hold on a boundary, the
     * source or a song's identity is not changed by transcribing again.
     */
    #[Test]
    public function it_refuses_a_run_whose_holds_are_not_transcript_loss(): void
    {
        $run = $this->heldRun(['found_by' => 'boundary', 'reason' => 'sermon opens mid-sentence']);

        $this->assertRefused($run, 'no live transcript-loss hold');
    }

    #[Test]
    public function it_refuses_a_hold_on_text_the_run_no_longer_holds(): void
    {
        // Re-transcribed since the hold was raised: the text it describes is gone.
        $run = $this->heldRun(['transcript_sha256' => str_repeat('f', 64)]);

        $this->assertRefused($run, 'no live transcript-loss hold');
    }

    #[Test]
    public function it_refuses_a_released_hold(): void
    {
        $run = $this->heldRun(['released_at' => now()->toIso8601String()]);

        $this->assertRefused($run, 'no live transcript-loss hold');
    }

    #[Test]
    public function it_accepts_an_operator_written_transcript_loss_reason(): void
    {
        Bus::fake();
        $run = $this->heldRun(['found_by' => 'source_audio', 'reason' => 'Saved sermon text repeats a loop over 90 s of preaching']);
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-retranscribe', ['snapshot' => $this->snapshotPath()])
            ->expectsOutputToContain('ready for re-transcription')
            ->assertSuccessful();
    }

    /**
     * No run is re-transcribed and re-detected on the same commit: whichever tier dispatches
     * first stamps it, and the other refuses.
     */
    #[Test]
    public function neither_tier_re_runs_a_run_the_other_dispatched_on_this_commit(): void
    {
        Bus::fake();
        $run = $this->heldRun();
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-retranscribe', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->assertSuccessful();

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('already re-run on this commit')
            ->assertSuccessful();
    }

    /**
     * 1112 is in the canary and held for transcript loss (operator, 2026-09-24): it goes through
     * Tier A. Tier B refuses it even when run first, so the order of the two commands cannot
     * strand it on known-wrong text.
     */
    #[Test]
    public function tier_b_leaves_a_run_held_for_transcript_loss_to_tier_a(): void
    {
        Bus::fake();
        $run = $this->heldRun();
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-redetect', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('held for transcript loss')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
        self::assertSame([], $run->fresh()?->corpusRerunStamps());

        $this->artisan('historic-import:rerun-retranscribe', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('dispatched from full-service transcription')
            ->assertSuccessful();
    }

    #[Test]
    public function it_refuses_when_the_staged_source_is_not_the_recorded_size(): void
    {
        Bus::fake();
        $run = $this->heldRun();
        $this->snapshot([$run->id]);
        Storage::disk('local')->put((string) $run->source_file_path, 'a different encode');

        $this->artisan('historic-import:rerun-retranscribe', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('staged source is not the recorded size')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
        self::assertSame(ProcessingStatus::Completed, $run->fresh()?->status);
    }

    #[Test]
    public function it_accepts_a_staged_source_of_the_recorded_size_without_hashing_it(): void
    {
        // Every source was hash-checked as it was staged; re-reading 10 GB a tier bought nothing.
        Bus::fake();
        $run = $this->heldRun();
        $run->forceFill(['file_hash' => str_repeat('0', 64)])->save();
        $this->snapshot([$run->id]);

        $this->artisan('historic-import:rerun-retranscribe', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('dispatched from full-service transcription')
            ->assertSuccessful();
    }

    private function assertRefused(MediaProcessingLog $run, string $reason, ?string $routingSha256 = null): void
    {
        Bus::fake();
        $this->snapshot([$run->id], $routingSha256);

        $this->artisan('historic-import:rerun-retranscribe', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain($reason)
            ->assertSuccessful();

        Bus::assertNothingDispatched();
        self::assertSame([], $run->fresh()?->corpusRerunStamps());
        self::assertSame(ProcessingStatus::Completed, $run->fresh()?->status);
    }

    /**
     * A completed run with one section carrying one hold record, by default the loop screen's.
     *
     * @param  array<string, mixed>  $hold
     */
    private function heldRun(array $hold = []): MediaProcessingLog
    {
        $run = $this->completedRun();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Sermon,
            'start_time' => 600,
            'end_time' => 2400,
            'duration' => 1800,
            'metadata' => [
                'review_flags' => [HoldSectionForContentReview::FLAG],
                HoldSectionForContentReview::METADATA_KEY => [[
                    'found_by' => 'loop_screen',
                    'reason' => 'transcript repeats a loop the audio does not',
                    'held_at' => now()->toIso8601String(),
                    ...$hold,
                ]],
            ],
        ]);

        return $run;
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

    /**
     * @param  list<int>  $runIds
     */
    private function snapshot(array $runIds, ?string $routingSha256 = null): void
    {
        $routing = $routingSha256 === null ? [] : ['--routing' => $this->routingPath(), '--routing-sha256' => $routingSha256];

        $this->artisan('historic-import:rerun-snapshot', ['runs' => $runIds, '--output' => $this->snapshotPath(), ...$routing])
            ->assertSuccessful();
    }

    private function snapshotPath(): string
    {
        return $this->directory.'/before.json';
    }

    /**
     * A bound routing file: each route with the canonical hash of the transcript it judged.
     *
     * @param  array<int, array{0: string, 1: string}>  $routes
     */
    private function routing(array $routes): string
    {
        $runs = [];

        foreach ($routes as $runId => [$route, $listened]) {
            $runs[(string) $runId] = ['route' => $route, 'verdicts' => [], 'listened_transcript_sha256' => $listened];
        }

        $contents = (string) json_encode(['kind' => 'h10b_listening_routing', 'runs' => $runs]);
        File::put(storage_path('app/private/'.$this->routingPath()), $contents);

        return hash('sha256', $contents);
    }

    /**
     * Store a one-cue transcript as the run's current text, returning its canonical hash.
     */
    private function storeTranscript(MediaProcessingLog $run, string $text): string
    {
        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 30.0, 'text' => $text],
        ], 2400.0, ChurchServiceTranscript::SOURCE_MOCK);

        $path = 'temp/service_transcript_'.$run->processing_id.'.json';
        Storage::disk('local')->put($path, (string) json_encode($transcript));
        $run->putServiceTranscriptPath($path);

        return ServiceTranscriptRedecoder::transcriptHash($transcript);
    }

    private function routingPath(): string
    {
        return $this->directory.'/routing.json';
    }
}
