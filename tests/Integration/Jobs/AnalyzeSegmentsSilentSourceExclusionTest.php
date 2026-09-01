<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Data\LivestreamSegment;
use App\Enums\ProcessingStatus;
use App\Jobs\AnalyzeSegments;
use App\Models\HistoricImportAlert;
use App\Models\MediaProcessingLog;
use App\Models\SermonProcessingStep;
use App\Services\Media\Video\VideoSegmentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

/**
 * A source recording with no usable audio is a first-class, terminal
 * exclusion — not a failure. AnalyzeSegments must record the evidence,
 * skip TranscribeFullService and DetectServiceStructure (there is nothing
 * for them to read), and still release the run's staging bytes rather than
 * stranding them the way a thrown exception would.
 *
 * @see docs/plans/SILENT-SOURCE-EXCLUSION-AND-ALERT-VISIBILITY-2026-09-01.md
 */
class AnalyzeSegmentsSilentSourceExclusionTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    #[Test]
    public function it_excludes_a_silent_run_and_still_releases_its_staging_bytes(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('temp/source-silent.mp4', 'not really a video');

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'rms_log_path' => 'temp/rms.log',
            'source_file_path' => 'temp/source-silent.mp4',
            'sermon_start_time' => null,
            'sermon_end_time' => null,
        ]);

        $mockService = Mockery::mock(VideoSegmentationService::class);
        $mockService->shouldReceive('analyzeSegments')
            ->once()
            ->andReturn([
                'segments' => [],
                'threshold_metadata' => ['method' => 'not_applicable', 'threshold' => 0.0],
                'silence_evidence' => ['frame_count' => 21012, 'rms_log_path' => 'temp/rms.log'],
            ]);

        $job = new AnalyzeSegments($processingLog);
        $job->handle($mockService);

        $processingLog->refresh();

        // Terminal disposition: excluded runs complete (there is nothing to
        // extract), never fail — and never touch Whisper or the LLM.
        $this->assertSame(ProcessingStatus::Completed, $processingLog->status);
        $this->assertTrue($processingLog->isExcludedSilentAudio());
        $this->assertEquals(
            ['frame_count' => 21012, 'rms_log_path' => 'temp/rms.log', 'source_path' => null, 'source_sha256' => null],
            $processingLog->silentAudioExclusionEvidence(),
        );
        $this->assertNull($processingLog->sermon_start_time);
        $this->assertNull($processingLog->sermon_end_time);

        $this->assertSame(
            ProcessingStatus::Completed,
            SermonProcessingStep::query()
                ->where('processing_id', $processingLog->processing_id)
                ->where('step', 'analyzing_segments')
                ->value('status'),
        );

        // The chain was cleared rather than left to run through jobs that
        // assume a resolvable sermon baseline or authoritative sections.
        $this->assertSame([], $job->chained);

        // CleanupTemporaryFiles is dispatched directly (QUEUE_CONNECTION=sync
        // in tests runs it inline) and actually released the staged source —
        // the constraint the plan exists to prove, not just the disposition.
        Storage::disk('local')->assertMissing('temp/source-silent.mp4');
    }

    #[Test]
    public function it_records_the_exclusion_through_the_historic_alert_channel(): void
    {
        Storage::fake('local');

        $operation = $this->createHistoricImportOperation();
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'historic_import_operation_id' => $operation->id,
            'rms_log_path' => 'temp/rms.log',
            'processing_metadata' => [
                'historic_import' => [
                    'operation_id' => $operation->operation_id,
                    'sources' => [
                        ['path' => '2026-04-02-evening/source.webm', 'size' => 183_569_962, 'mtime' => 1_700_000_000, 'sha256' => str_repeat('d', 64)],
                    ],
                ],
            ],
        ]);

        $mockService = Mockery::mock(VideoSegmentationService::class);
        $mockService->shouldReceive('analyzeSegments')
            ->once()
            ->andReturn([
                'segments' => [],
                'threshold_metadata' => ['method' => 'not_applicable', 'threshold' => 0.0],
                'silence_evidence' => ['frame_count' => 21012, 'rms_log_path' => 'temp/rms.log'],
            ]);

        (new AnalyzeSegments($processingLog))->handle($mockService);

        $processingLog->refresh();

        $this->assertSame(
            str_repeat('d', 64),
            $processingLog->silentAudioExclusionEvidence()['source_sha256'] ?? null,
        );

        $this->assertDatabaseHas('historic_import_alerts', [
            'historic_import_operation_id' => $operation->id,
            'kind' => 'excluded_source_audio_silent',
            'severity' => 'warning',
        ]);

        // No email: historic notification_mode is external_disabled, and the
        // alert channel is the only durable record of why the run stopped.
        $alert = HistoricImportAlert::query()
            ->where('historic_import_operation_id', $operation->id)
            ->where('kind', 'excluded_source_audio_silent')
            ->firstOrFail();

        $this->assertSame(21012, $alert->payload['facts']['frame_count']);
        $this->assertSame('2026-04-02-evening/source.webm', $alert->payload['facts']['source_path']);
    }

    #[Test]
    public function a_non_silent_run_is_unaffected(): void
    {
        Storage::fake('local');

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'rms_log_path' => 'temp/rms.log',
            'sermon_start_time' => null,
            'sermon_end_time' => null,
        ]);

        $mockService = Mockery::mock(VideoSegmentationService::class);
        $mockService->shouldReceive('analyzeSegments')
            ->once()
            ->andReturn([
                'segments' => [
                    new LivestreamSegment(
                        startTime: 0.0,
                        endTime: 600.0,
                        duration: 600.0,
                        classification: 'speech',
                        avgRms: -48.0,
                        peakRms: -38.0,
                        segmentOrder: 0,
                    ),
                ],
                'threshold_metadata' => ['method' => 'adaptive', 'threshold' => -45.0],
                'silence_evidence' => null,
            ]);

        (new AnalyzeSegments($processingLog))->handle($mockService);

        $processingLog->refresh();

        $this->assertFalse($processingLog->isExcludedSilentAudio());
        $this->assertNull($processingLog->silentAudioExclusionEvidence());
        $this->assertEqualsWithDelta(0.0, (float) $processingLog->sermon_start_time, 0.01);
        $this->assertEqualsWithDelta(600.0, (float) $processingLog->sermon_end_time, 0.01);
    }
}
