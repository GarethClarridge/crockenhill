<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\RedetectStructureOnRecoveredEvidence;
use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class RedetectRecoveredStructureCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    private const SOURCE = 'livestream/temp/source.mp4';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Config::set('media-processing.storage.temp_disk', 'local');
        Config::set('media-processing.storage.transcript_disk', 'local');
        Config::set('media-processing.storage.sermon_disk', 'local');
    }

    #[Test]
    public function it_does_not_dispatch_without_execute(): void
    {
        Bus::fake();
        $log = $this->replayedRun();

        $this->artisan('historic-import:redetect-recovered-structure', ['run' => [$log->id]])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('would dispatch')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
        self::assertSame(ProcessingStatus::Completed, $log->fresh()?->status);
        self::assertNull($log->fresh()?->processing_metadata?->toArray()[RedetectStructureOnRecoveredEvidence::SNAPSHOT_KEY] ?? null);
    }

    #[Test]
    public function it_refuses_a_run_whose_transcript_was_never_corrected(): void
    {
        Bus::fake();
        $log = $this->replayedRun(replayed: false);

        $this->artisan('historic-import:redetect-recovered-structure', ['run' => [$log->id], '--execute' => true])
            ->expectsOutputToContain('transcript was never corrected')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_refuses_a_run_whose_source_recording_is_gone(): void
    {
        Bus::fake();
        $log = $this->replayedRun();
        Storage::disk('local')->delete(self::SOURCE);

        // Detection would succeed and extraction would then fail, leaving a
        // finished run broken.
        $this->artisan('historic-import:redetect-recovered-structure', ['run' => [$log->id], '--execute' => true])
            ->expectsOutputToContain('source recording is gone')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
        self::assertSame(ProcessingStatus::Completed, $log->fresh()?->status);
    }

    #[Test]
    public function it_snapshots_the_structure_before_dispatching(): void
    {
        Bus::fake();
        $log = $this->replayedRun();

        $this->artisan('historic-import:redetect-recovered-structure', ['run' => [$log->id], '--execute' => true])
            ->expectsOutputToContain('dispatched')
            ->assertSuccessful();

        $snapshot = $log->fresh()?->processing_metadata?->toArray()[RedetectStructureOnRecoveredEvidence::SNAPSHOT_KEY] ?? null;

        self::assertIsArray($snapshot);
        self::assertCount(1, $snapshot['sections']);
        self::assertSame('other', $snapshot['sections'][0]['section_type']);
        self::assertEqualsWithDelta(120.0, $snapshot['sermon_start_time'], 0.001);
        self::assertNotNull($snapshot['taken_at']);
    }

    /**
     * Run #1035 is the case this covers: it projected one prayer from a 22-word
     * transcript, then failed downstream at extraction because no speech block
     * met the 20-minute sermon threshold. Its ordinary retry plan resumes at the
     * extraction phase and re-reads that same structure, so re-detection is the
     * only route back to the evidence the replay restored.
     */
    #[Test]
    public function it_redetects_a_failed_run_whose_transcript_was_corrected(): void
    {
        Bus::fake();
        $log = $this->replayedRun(status: ProcessingStatus::Failed);

        $this->artisan('historic-import:redetect-recovered-structure', ['run' => [$log->id], '--execute' => true])
            ->expectsOutputToContain('dispatched')
            ->assertSuccessful();

        $snapshot = $log->fresh()?->processing_metadata?->toArray()[RedetectStructureOnRecoveredEvidence::SNAPSHOT_KEY] ?? null;

        self::assertIsArray($snapshot);
        self::assertCount(1, $snapshot['sections']);
    }

    #[Test]
    public function it_refuses_a_run_that_is_still_in_flight(): void
    {
        Bus::fake();
        $log = $this->replayedRun(status: ProcessingStatus::Processing);

        $this->artisan('historic-import:redetect-recovered-structure', ['run' => [$log->id], '--execute' => true])
            ->expectsOutputToContain('needs a run that has settled')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    /**
     * Run #1004's replay found nothing: 4,317 blind seconds, both banked retries
     * empty. The stamp proves a replay ran, not that it recovered anything, so
     * re-detection would spend two provider calls re-reading the same evidence.
     */
    #[Test]
    public function it_refuses_a_run_whose_replay_recovered_no_words(): void
    {
        Bus::fake();
        $log = $this->replayedRun(wordsRecovered: 0);

        $this->artisan('historic-import:redetect-recovered-structure', ['run' => [$log->id], '--execute' => true])
            ->expectsOutputToContain('no words recovered')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
        self::assertNull($log->fresh()?->processing_metadata?->toArray()[RedetectStructureOnRecoveredEvidence::SNAPSHOT_KEY] ?? null);
    }

    #[Test]
    public function it_refuses_a_run_it_has_already_redetected(): void
    {
        Bus::fake();
        $log = $this->replayedRun();

        $this->artisan('historic-import:redetect-recovered-structure', ['run' => [$log->id], '--execute' => true])
            ->assertSuccessful();

        $this->artisan('historic-import:redetect-recovered-structure', ['run' => [$log->id], '--execute' => true])
            ->expectsOutputToContain('already re-derived')
            ->assertSuccessful();
    }

    private function replayedRun(
        bool $replayed = true,
        ProcessingStatus $status = ProcessingStatus::Completed,
        int $wordsRecovered = 5_855,
    ): MediaProcessingLog {
        $operation = $this->createHistoricImportOperation();

        // No staging context: the guard would refuse a fabricated storage
        // identity, and the disk rule under test is the same either way.
        $metadata = [
            'historic_import' => ['operation_id' => $operation->operation_id],
            'sermon_extraction_plan' => ['segments' => [['start_time' => 120.0, 'end_time' => 900.0]]],
            'service_structure' => ['sections' => []],
        ];

        if ($replayed) {
            $metadata['transcript_recovery_replay'] = [
                'replayed_at' => now()->toIso8601String(),
                'previous_path' => 'old.json',
                'words_before' => 22,
                'words_after' => 22 + $wordsRecovered,
            ];
        }

        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'historic_import_operation_id' => $operation->id,
            'status' => $status,
            'source_file_path' => self::SOURCE,
            'sermon_start_time' => 120.0,
            'sermon_end_time' => 900.0,
            'current_step' => $status === ProcessingStatus::Failed ? 'manual_review_required' : 'completed',
            'processing_metadata' => $metadata,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Other,
            'start_time' => 120.0,
            'end_time' => 900.0,
        ]);

        Storage::disk('local')->put(self::SOURCE, 'video bytes');

        return $log->fresh() ?? $log;
    }
}
