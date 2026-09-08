<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Data\ChurchServiceTranscript;
use App\Enums\SermonPublicationState;
use App\Jobs\ProcessTranscriptWithAI;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\HistoricMedia\HistoricTranscriptRecoveryReplay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

/**
 * The shape these runs are in: a 300-second window looped over its first 150
 * seconds and held a sermon over the rest, the whole retry was rejected, and the
 * whole window was banked unobservable with every recovered word deleted.
 */
class ReplayHistoricTranscriptRecoveryCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    private const WINDOW_START = 600.0;

    private const WINDOW_END = 900.0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Config::set('media-processing.storage.temp_disk', 'local');
        Config::set('media-processing.storage.transcript_disk', 'local');
    }

    #[Test]
    public function it_reports_the_recovery_without_writing_by_default(): void
    {
        [$operation, $log] = $this->historicRun();

        $this->artisan('historic-import:replay-transcript-recovery', [
            '--operation' => $operation->operation_id,
        ])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('1 transcript(s) would be replayed.')
            ->assertSuccessful();

        $fresh = $log->fresh();
        self::assertSame($this->bankedTranscriptPath($log), $fresh?->serviceTranscriptPath());
        self::assertCount(1, $fresh?->serviceTranscriptUnobservableWindows() ?? []);
        self::assertSame(self::WINDOW_END, ($fresh?->serviceTranscriptUnobservableWindows() ?? [])[0]['end']);
    }

    #[Test]
    public function it_banks_the_recovered_speech_and_narrows_the_window_to_the_residual_loop(): void
    {
        [$operation, $log] = $this->historicRun();

        $this->artisan('historic-import:replay-transcript-recovery', [
            '--operation' => $operation->operation_id,
            '--execute' => true,
        ])
            ->expectsOutputToContain('Banked 1 replayed transcript(s).')
            ->assertSuccessful();

        $fresh = $log->fresh();
        $path = (string) $fresh?->serviceTranscriptPath();

        self::assertStringContainsString(HistoricTranscriptRecoveryReplay::REPLAYED_KIND, $path);
        Storage::disk('local')->assertExists($path);

        // The window now covers only the part that still loops, not the sermon
        // behind it.
        $windows = $fresh?->serviceTranscriptUnobservableWindows() ?? [];
        self::assertCount(1, $windows);
        self::assertSame(self::WINDOW_START, $windows[0]['start']);
        self::assertSame(750.0, $windows[0]['end']);

        $replayed = ChurchServiceTranscript::fromArray(
            json_decode((string) Storage::disk('local')->get($path), true),
        );
        $texts = array_column($replayed->cues, 'text');

        self::assertContains('The sermon the old rule deleted.', $texts);
        self::assertContains('Before the window.', $texts);
        self::assertNotContains('Amen.', $texts);
    }

    #[Test]
    public function it_leaves_the_pre_fix_transcript_in_place_as_evidence(): void
    {
        [$operation, $log] = $this->historicRun();
        $original = $this->bankedTranscriptPath($log);

        $this->artisan('historic-import:replay-transcript-recovery', [
            '--operation' => $operation->operation_id,
            '--execute' => true,
        ])->assertSuccessful();

        Storage::disk('local')->assertExists($original);
        self::assertNotSame($original, $log->fresh()?->serviceTranscriptPath());
    }

    #[Test]
    public function it_reports_an_already_replayed_run_rather_than_replaying_it_twice(): void
    {
        [$operation] = $this->historicRun();

        $this->artisan('historic-import:replay-transcript-recovery', [
            '--operation' => $operation->operation_id,
            '--execute' => true,
        ])->assertSuccessful();

        $this->artisan('historic-import:replay-transcript-recovery', [
            '--operation' => $operation->operation_id,
        ])
            ->expectsOutputToContain('already replayed')
            ->expectsOutputToContain('0 transcript(s) would be replayed.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_keeps_the_window_blind_when_the_banked_retry_is_gone(): void
    {
        [$operation, $log] = $this->historicRun();
        Storage::disk('local')->delete($this->retryPath($log));

        $this->artisan('historic-import:replay-transcript-recovery', [
            '--operation' => $operation->operation_id,
            '--execute' => true,
        ])->assertSuccessful();

        $windows = $log->fresh()?->serviceTranscriptUnobservableWindows() ?? [];

        // A missing retry is an infrastructure outcome, never a verdict on the
        // audio: the whole window stays flagged and nothing is invented.
        self::assertCount(1, $windows);
        self::assertSame(self::WINDOW_END, $windows[0]['end']);
        self::assertSame('retranscription_unavailable', $windows[0]['reason']);
    }

    #[Test]
    public function it_refuses_to_reanalyse_without_executing(): void
    {
        [$operation] = $this->historicRun();

        $this->artisan('historic-import:replay-transcript-recovery', [
            '--operation' => $operation->operation_id,
            '--reanalyse' => true,
        ])
            ->expectsOutputToContain('--reanalyse requires --execute')
            ->assertFailed();
    }

    #[Test]
    public function it_rederives_a_single_span_sermon_transcript_and_dispatches_analysis(): void
    {
        Queue::fake();
        [$operation, $log] = $this->historicRun(withSermon: true);
        $sermon = $log->sermon;

        $this->artisan('historic-import:replay-transcript-recovery', [
            '--operation' => $operation->operation_id,
            '--execute' => true,
            '--reanalyse' => true,
        ])
            ->expectsOutputToContain('Re-derived 1 sermon transcript(s).')
            ->expectsOutputToContain('Dispatched 1 re-analysis job(s)')
            ->assertSuccessful();

        // The span repair's own defect needs two spans; this one does not, and
        // the recovered sermon must reach the banked transcript regardless.
        $rewritten = (string) Storage::disk('local')->get((string) $sermon?->fresh()?->transcript_file_path);
        self::assertStringContainsString('The sermon the old rule deleted.', $rewritten);

        Queue::assertPushed(ProcessTranscriptWithAI::class, 1);
    }

    #[Test]
    public function it_reanalyses_a_run_replayed_by_an_earlier_pass(): void
    {
        Queue::fake();
        [$operation] = $this->historicRun(withSermon: true);

        $this->artisan('historic-import:replay-transcript-recovery', [
            '--operation' => $operation->operation_id,
            '--execute' => true,
        ])->assertSuccessful();

        Queue::assertNothingPushed();

        $this->artisan('historic-import:replay-transcript-recovery', [
            '--operation' => $operation->operation_id,
            '--execute' => true,
            '--reanalyse' => true,
        ])
            ->expectsOutputToContain('already replayed')
            ->expectsOutputToContain('Dispatched 1 re-analysis job(s)')
            ->assertSuccessful();

        Queue::assertPushed(ProcessTranscriptWithAI::class, 1);
    }

    /**
     * @return array{0: HistoricImportOperation, 1: MediaProcessingLog}
     */
    private function historicRun(bool $withSermon = false): array
    {
        $operation = $this->createHistoricImportOperation();

        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'historic_import_operation_id' => $operation->id,
            'sermon_start_time' => 700.0,
            'sermon_end_time' => 950.0,
            'processing_metadata' => [
                'historic_import' => ['operation_id' => $operation->operation_id],
                // One span, deliberately: the sermon-span repair declines a
                // single-span plan because its own defect cannot occur there.
                'sermon_extraction_plan' => ['segments' => [['start_time' => 700.0, 'end_time' => 950.0]]],
            ],
        ]);

        if ($withSermon) {
            $sermon = Sermon::factory()->fromLivestream()->create([
                'livestream_processing_id' => $log->processing_id,
                'historic_import_operation_id' => $operation->id,
                'publication_state' => SermonPublicationState::Quarantined,
                'asset_disk' => 'local',
            ]);
            $log->forceFill(['sermon_id' => $sermon->id])->save();

            $sermonTranscriptPath = 'transcripts/sermon_'.$sermon->id.'.md';
            $sermon->forceFill(['transcript_file_path' => $sermonTranscriptPath])->save();
            // What the old rule left inside the span: the closing line only.
            Storage::disk('local')->put($sermonTranscriptPath, 'After the window.');
        }

        // The provider response the run banked, loop and all.
        Storage::disk('local')->put(
            $this->rawTranscriptPath($log),
            json_encode(['duration' => 1200.0, 'segments' => $this->loopingSegments()], JSON_THROW_ON_ERROR),
        );

        // The retry it banked, and then discarded because its first half loops.
        Storage::disk('local')->put(
            $this->retryPath($log),
            json_encode(['duration' => 300.0, 'segments' => $this->partlyLoopingRetrySegments()], JSON_THROW_ON_ERROR),
        );

        // What the old rule left: the window's cues deleted, the whole window blind.
        $banked = ChurchServiceTranscript::fromCues(
            [
                ['start' => 0.0, 'end' => 30.0, 'text' => 'Before the window.'],
                ['start' => 950.0, 'end' => 980.0, 'text' => 'After the window.'],
            ],
            1200.0,
            ChurchServiceTranscript::SOURCE_LOCAL_WHISPER,
            [['start' => self::WINDOW_START, 'end' => self::WINDOW_END, 'reason' => 'retranscription_failed']],
        );

        Storage::disk('local')->put($this->bankedTranscriptPath($log), json_encode($banked->toArray(), JSON_THROW_ON_ERROR));
        $log->putServiceTranscriptPath($this->bankedTranscriptPath($log), $banked->unobservableWindows);

        return [$operation, $log->fresh()?->load('sermon')];
    }

    /**
     * A whole-service transcript whose 600–900 s stretch repeats one phrase, so
     * the detector selects exactly that window.
     *
     * @return list<array{start: float, end: float, text: string}>
     */
    private function loopingSegments(): array
    {
        $segments = [['start' => 0.0, 'end' => 30.0, 'text' => 'Before the window.']];

        for ($start = self::WINDOW_START; $start < self::WINDOW_END; $start += 10.0) {
            $segments[] = ['start' => $start, 'end' => $start + 10.0, 'text' => 'Amen.'];
        }

        $segments[] = ['start' => 950.0, 'end' => 980.0, 'text' => 'After the window.'];

        return $segments;
    }

    /**
     * The retry, on the clip's own clock: 150 seconds still looping, then the
     * sermon. The old rule rejected all of it for the first half.
     *
     * @return list<array{start: float, end: float, text: string}>
     */
    private function partlyLoopingRetrySegments(): array
    {
        $segments = [];

        for ($start = 0.0; $start < 150.0; $start += 10.0) {
            $segments[] = ['start' => $start, 'end' => $start + 10.0, 'text' => 'Amen.'];
        }

        $segments[] = ['start' => 150.0, 'end' => 300.0, 'text' => 'The sermon the old rule deleted.'];

        return $segments;
    }

    private function bankedTranscriptPath(MediaProcessingLog $log): string
    {
        return 'service-transcripts/2024-10-06/morning-'.$log->processing_id.'.normalized.json';
    }

    private function rawTranscriptPath(MediaProcessingLog $log): string
    {
        return 'service-transcripts/2024-10-06/morning-'.$log->processing_id.'.raw.json';
    }

    private function retryPath(MediaProcessingLog $log): string
    {
        return 'service-transcripts/unknown-date/other-'.$log->processing_id.'-recovery-1.raw.json';
    }
}
