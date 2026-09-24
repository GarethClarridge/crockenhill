<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\HoldSectionForContentReview;
use App\Actions\RetranscribeForCorpusRerun;
use App\Data\HistoricStagingContext;
use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionType;
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
        self::assertTrue($fresh?->isReExtraction());

        $stamps = $fresh?->corpusRerunStamps() ?? [];
        self::assertCount(1, $stamps);
        self::assertSame(RetranscribeForCorpusRerun::TIER, $stamps[0]['tier']);
        self::assertSame('corpus_rerun', $stamps[0]['grounds']);
        self::assertCount(1, $stamps[0]['transcript_loss_sections']);
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

    #[Test]
    public function it_refuses_when_the_staged_source_hash_does_not_match(): void
    {
        Bus::fake();
        $run = $this->heldRun();
        $this->snapshot([$run->id]);
        Storage::disk('local')->put((string) $run->source_file_path, 'a different encode');

        $this->artisan('historic-import:rerun-retranscribe', ['snapshot' => $this->snapshotPath(), '--execute' => true])
            ->expectsOutputToContain('staged source hash does not match')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
        self::assertSame(ProcessingStatus::Completed, $run->fresh()?->status);
    }

    private function assertRefused(MediaProcessingLog $run, string $reason): void
    {
        Bus::fake();
        $this->snapshot([$run->id]);

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

    private function snapshotPath(): string
    {
        return $this->directory.'/before.json';
    }
}
