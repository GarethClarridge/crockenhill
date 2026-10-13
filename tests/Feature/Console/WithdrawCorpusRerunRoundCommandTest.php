<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\RedetectForCorpusRerun;
use App\Actions\RetranscribeForCorpusRerun;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Models\SermonProcessingStep;
use App\Services\HistoricMedia\QueuedRunJobs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

/**
 * The full pass left 99 runs `processing` with nothing queued (84 cuts pulled for the amended-D1
 * hold list, 15 re-transcriptions that never finished) and six failed for faults since fixed.
 */
class WithdrawCorpusRerunRoundCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    private string $directory;

    /** @var list<string> */
    private array $queued = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = 'rerun-withdraw-test-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists(storage_path('app/private/'.$this->directory));

        $queuedJobs = Mockery::mock(QueuedRunJobs::class);
        $queuedJobs->shouldReceive('naming')->andReturnUsing(fn (): array => $this->queued);
        $this->app->instance(QueuedRunJobs::class, $queuedJobs);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/private/'.$this->directory));

        parent::tearDown();
    }

    #[Test]
    public function a_cut_pulled_before_it_was_stored_is_withdrawn_to_deferred_media(): void
    {
        $run = $this->pulledCut();

        $this->withdraw([$run->id], execute: false)->expectsOutputToContain('ready to withdraw its unfinished extraction')->assertSuccessful();
        self::assertSame(ProcessingStatus::Processing, $run->fresh()->status);

        $this->withdraw([$run->id])->expectsOutputToContain('withdrew its unfinished extraction')->assertSuccessful();

        $run->refresh();
        $stamp = $run->corpusRerunStamps()[0];
        self::assertSame(ProcessingStatus::Completed, $run->status);
        self::assertSame('completed', $run->current_step);
        self::assertSame('deferred', $stamp['media']);
        self::assertNull($stamp['extraction_dispatched_at']);
        self::assertSame('2026-10-11T12:00:00+00:00', $stamp['extraction_withdrawn']['dispatched_at']);
        self::assertSame('held for the amended-D1 follow-up round', $stamp['extraction_withdrawn']['reason']);
        self::assertSame('processing', $stamp['extraction_withdrawn']['status']);
        self::assertArrayNotHasKey('re_extraction', $run->processing_metadata->toArray());
        self::assertTrue($run->hasDeferredCorpusRerunMedia());
    }

    #[Test]
    public function a_failed_cut_whose_video_was_never_stored_is_withdrawn(): void
    {
        $run = $this->pulledCut(['status' => ProcessingStatus::Failed, 'current_step' => 'extraction', 'error_message' => 'Invalid selected section bounds']);

        $this->withdraw([$run->id])->assertSuccessful();

        self::assertSame(ProcessingStatus::Completed, $run->fresh()->status);
        self::assertNull($run->fresh()->error_message);
    }

    #[Test]
    public function an_unfinished_retranscription_is_withdrawn_and_passed_over_from_then_on(): void
    {
        $run = $this->completedRun();
        $this->snapshot([$run->id]);
        $run->putCorpusRerunStamp($this->stamp(['tier' => RetranscribeForCorpusRerun::TIER, 'detection' => RetranscribeForCorpusRerun::DETECTION_NONE]));
        $run->forceFill(['status' => ProcessingStatus::Processing, 'current_step' => 'segmenting'])->save();

        $this->withdraw([$run->id])->expectsOutputToContain('withdrew its unfinished re-transcription')->assertSuccessful();

        $run->refresh();
        self::assertSame(ProcessingStatus::Completed, $run->status);
        self::assertSame([], $run->corpusRerunStamps());
        self::assertSame('held for the amended-D1 follow-up round', $run->processing_metadata[RedetectForCorpusRerun::STAMP_KEY][0]['withdrawn']['reason'] ?? null);
    }

    #[Test]
    public function a_finished_retranscription_is_refused(): void
    {
        $run = $this->completedRun();
        $this->snapshot([$run->id]);
        $run->putCorpusRerunStamp($this->stamp(['detection' => RetranscribeForCorpusRerun::DETECTION_NONE, 'transcribed_at' => '2026-10-11T13:00:00+00:00']));
        $run->forceFill(['status' => ProcessingStatus::Processing])->save();

        $this->withdraw([$run->id])->expectsOutputToContain('its re-transcription finished')->assertSuccessful();
        self::assertSame(ProcessingStatus::Processing, $run->fresh()->status);
    }

    #[Test]
    public function a_retranscription_whose_transcript_changed_is_refused(): void
    {
        $run = $this->completedRun();
        $this->snapshot([$run->id]);
        $this->rewriteSnapshot(function (array $snapshot) use ($run): array {
            $snapshot['runs'][$run->id]['transcript_sha256'] = str_repeat('e', 64);

            return $snapshot;
        });
        $run->putCorpusRerunStamp($this->stamp(['detection' => RetranscribeForCorpusRerun::DETECTION_NONE]));
        $run->forceFill(['status' => ProcessingStatus::Processing])->save();

        $this->withdraw([$run->id])->expectsOutputToContain('its transcript has changed since the snapshot')->assertSuccessful();
        self::assertSame(ProcessingStatus::Processing, $run->fresh()->status);
    }

    #[Test]
    public function a_run_a_queued_job_still_names_is_refused(): void
    {
        $run = $this->pulledCut();
        $this->queued = ['delayed App\\Jobs\\ExtractSermon on historic-ffmpeg'];

        $this->withdraw([$run->id])->expectsOutputToContain('jobs still name the run: delayed App\\Jobs\\ExtractSermon on historic-ffmpeg')->assertSuccessful();
        self::assertSame(ProcessingStatus::Processing, $run->fresh()->status);
    }

    #[Test]
    public function a_run_with_unsettled_media_work_is_refused(): void
    {
        $run = $this->pulledCut(['historic_import_operation_id' => $this->createHistoricImportOperation()->id]);
        SermonProcessingStep::factory()->create([
            'processing_id' => $run->processing_id,
            'step' => 'assessing_video_quality',
            'status' => ProcessingStatus::Started,
        ]);

        $this->withdraw([$run->id])->expectsOutputToContain('its media work is unsettled: assessing_video_quality (state: started)')->assertSuccessful();
        self::assertSame(ProcessingStatus::Processing, $run->fresh()->status);
    }

    #[Test]
    public function a_cut_stored_after_its_dispatch_is_refused(): void
    {
        $run = $this->pulledCut();
        $run->writeProcessingMetadata(static fn (array $metadata): array => [...$metadata, 'stored_video' => ['stored_at' => '2026-10-11T12:30:00.000000Z']]);

        $this->withdraw([$run->id])->expectsOutputToContain('a sermon video was stored at 2026-10-11T12:30:00.000000Z')->assertSuccessful();
        self::assertSame(ProcessingStatus::Processing, $run->fresh()->status);
    }

    #[Test]
    public function a_run_waiting_on_a_manual_review_is_refused(): void
    {
        $run = $this->pulledCut(['status' => ProcessingStatus::Failed]);
        $run->writeProcessingMetadata(static fn (array $metadata): array => [...$metadata, 'manual_review' => ['status' => 'required', 'reason_code' => 'sermon_section_content_held']]);

        $this->withdraw([$run->id])->expectsOutputToContain('run waits on a manual review')->assertSuccessful();
        self::assertSame(ProcessingStatus::Failed, $run->fresh()->status);
    }

    #[Test]
    public function a_round_dispatched_against_another_snapshot_is_refused(): void
    {
        $run = $this->pulledCut(stampOverrides: ['snapshot_file_sha256' => str_repeat('f', 64)]);

        $this->withdraw([$run->id])->expectsOutputToContain('was not dispatched against this snapshot')->assertSuccessful();
    }

    #[Test]
    public function a_completed_run_is_refused(): void
    {
        $run = $this->pulledCut(['status' => ProcessingStatus::Completed]);

        $this->withdraw([$run->id])->expectsOutputToContain('run is completed')->assertSuccessful();
    }

    #[Test]
    public function a_reason_is_required(): void
    {
        $run = $this->pulledCut();

        $this->artisan('historic-import:rerun-withdraw', ['snapshot' => $this->snapshotPath(), 'runs' => [$run->id], '--execute' => true])
            ->expectsOutputToContain('--reason is required')
            ->assertFailed();
    }

    /**
     * A detection round whose cut was dispatched, then pulled from the queue after its edges were
     * decoded: `processing` with nothing left to run.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $stampOverrides
     */
    private function pulledCut(array $attributes = [], array $stampOverrides = []): MediaProcessingLog
    {
        $run = $this->completedRun();
        $this->snapshot([$run->id]);
        $run->putCorpusRerunStamp($this->stamp([
            'media' => 'extracted',
            'media_recorded_at' => '2026-10-11T11:00:00+00:00',
            'extraction_dispatched_at' => '2026-10-11T12:00:00+00:00',
            ...$stampOverrides,
        ]));
        $run->markAsReExtraction();
        $run->forceFill(['status' => ProcessingStatus::Processing, 'current_step' => 'transcribe_output_edges', ...$attributes])->save();

        return $run->refresh();
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function stamp(array $fields): array
    {
        return [
            'grounds' => 'corpus_rerun',
            'git_commit' => 'an-earlier-commit',
            'dispatched_at' => '2026-10-11T10:00:00+00:00',
            'snapshot_file_sha256' => hash_file('sha256', storage_path('app/private/'.$this->snapshotPath())),
            ...$fields,
        ];
    }

    private function completedRun(): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->livestream()->completed()->create(['current_step' => 'completed']);
    }

    /**
     * @param  list<int>  $runIds
     */
    private function withdraw(array $runIds, bool $execute = true): PendingCommand
    {
        return $this->artisan('historic-import:rerun-withdraw', [
            'snapshot' => $this->snapshotPath(),
            'runs' => $runIds,
            '--reason' => 'held for the amended-D1 follow-up round',
            '--execute' => $execute,
        ]);
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
     * @param  \Closure(array<string, mixed>): array<string, mixed>  $change
     */
    private function rewriteSnapshot(\Closure $change): void
    {
        $path = storage_path('app/private/'.$this->snapshotPath());
        file_put_contents($path, json_encode($change(json_decode((string) file_get_contents($path), true))));
    }

    private function snapshotPath(): string
    {
        return $this->directory.'/before.json';
    }
}
