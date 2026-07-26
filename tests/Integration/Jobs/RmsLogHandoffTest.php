<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Jobs\AnalyzeSegments;
use App\Jobs\GenerateRmsLog;
use App\Models\MediaProcessingLog;
use App\Services\Media\Video\VideoSegmentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GenerateRmsLog and AnalyzeSegments run back to back in the livestream chain
 * (ProcessingPipelineBuilder::buildLivestreamParallelJobs + ChainJobs). They are
 * otherwise only ever tested apart, each stubbing the disk the other uses — which
 * is how the archived RMS log came to be written to the transcript disk and read
 * back from the temp disk. In production those are genuinely different disks, so
 * this test keeps them different too.
 */
class RmsLogHandoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.transcript_disk' => 'public',
            'media-processing.file_wait_retry_delay_seconds' => 0,
        ]);
    }

    #[Test]
    public function analyze_segments_reads_the_rms_log_generate_rms_log_archived(): void
    {
        $log = $this->livestreamLogWithSourceVideo();

        $segmentation = $this->createMock(VideoSegmentationService::class);
        $segmentation->expects($this->once())
            ->method('generateRmsLog')
            ->willReturnCallback(function (): string {
                $path = 'temp/rms_'.uniqid().'.log';
                Storage::disk('local')->put($path, $this->rmsLogContents());

                return $path;
            });

        (new GenerateRmsLog($log))->handle($segmentation);

        $log->refresh();
        $this->assertStringStartsWith('service-transcripts/', (string) $log->rms_log_path);
        Storage::disk('public')->assertExists((string) $log->rms_log_path);

        // The real service, not a mock: the disk resolution is what is under test.
        $analysis = app(VideoSegmentationService::class)->analyzeSegments((string) $log->rms_log_path);

        $this->assertArrayHasKey('segments', $analysis);
        $this->assertNotEmpty($analysis['segments']);
    }

    #[Test]
    public function analyze_segments_still_reads_a_legacy_temp_disk_rms_log(): void
    {
        $legacyPath = 'temp/rms_legacy.log';
        Storage::disk('local')->put($legacyPath, $this->rmsLogContents());

        $analysis = app(VideoSegmentationService::class)->analyzeSegments($legacyPath);

        $this->assertArrayHasKey('segments', $analysis);
        $this->assertNotEmpty($analysis['segments']);
    }

    private function livestreamLogWithSourceVideo(): MediaProcessingLog
    {
        $sourcePath = 'temp/livestream-source.mp4';
        Storage::disk('local')->put($sourcePath, str_repeat('video-bytes', 1024));

        return MediaProcessingLog::factory()->livestream()->pending()->create([
            'source_file_path' => $sourcePath,
            'file_size' => Storage::disk('local')->size($sourcePath),
            'extracted_date' => '2026-03-22',
        ]);
    }

    /**
     * A short ffmpeg astats log: loud enough to yield at least one segment.
     */
    private function rmsLogContents(): string
    {
        $lines = [];

        for ($frame = 0; $frame < 240; $frame++) {
            $level = $frame < 40 || $frame > 200 ? '-70.0' : '-18.0';
            $lines[] = 'frame:'.$frame.' pts:'.($frame * 1024).' pts_time:'.number_format($frame * 0.5, 2);
            $lines[] = 'lavfi.astats.Overall.RMS_level='.$level;
        }

        return implode("\n", $lines)."\n";
    }
}
