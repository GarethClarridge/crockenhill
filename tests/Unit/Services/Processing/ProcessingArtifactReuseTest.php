<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Processing;

use App\Models\MediaProcessingLog;
use App\Services\Processing\ProcessingArtifactReuse;
use App\Support\ServiceArtifactDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessingArtifactReuseTest extends TestCase
{
    use RefreshDatabase;

    private const RmsPath = ServiceArtifactDisk::DURABLE_PREFIX.'2026-09-05/morning.rms.json';

    private const TranscriptPath = ServiceArtifactDisk::DURABLE_PREFIX.'2026-09-05/morning.normalised.json';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('transcripts');
        config(['media-processing.storage.transcript_disk' => 'transcripts']);
    }

    #[Test]
    public function it_reuses_an_rms_log_that_still_parses_to_frames(): void
    {
        Storage::disk('transcripts')->put(self::RmsPath, $this->rmsLog());

        $log = MediaProcessingLog::factory()->livestream()->create([
            'rms_log_path' => self::RmsPath,
        ]);

        $this->assertTrue($this->reuse()->rmsLogIsUsable($log));
    }

    #[Test]
    public function it_will_not_reuse_an_rms_log_the_run_never_recorded(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create(['rms_log_path' => null]);

        $this->assertFalse($this->reuse()->rmsLogIsUsable($log));
    }

    #[Test]
    public function it_will_not_reuse_an_rms_log_whose_file_is_gone(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'rms_log_path' => self::RmsPath,
        ]);

        $this->assertFalse($this->reuse()->rmsLogIsUsable($log));
    }

    /**
     * The power cut that makes reuse worth having is also what leaves
     * zero-length files behind, so an empty artifact must never be adopted.
     */
    #[Test]
    public function it_will_not_reuse_an_empty_rms_log(): void
    {
        Storage::disk('transcripts')->put(self::RmsPath, '');

        $log = MediaProcessingLog::factory()->livestream()->create([
            'rms_log_path' => self::RmsPath,
        ]);

        $this->assertFalse($this->reuse()->rmsLogIsUsable($log));
    }

    #[Test]
    public function it_will_not_reuse_an_rms_log_holding_no_frames(): void
    {
        Storage::disk('transcripts')->put(self::RmsPath, "frame:0\nnothing parseable here\n");

        $log = MediaProcessingLog::factory()->livestream()->create([
            'rms_log_path' => self::RmsPath,
        ]);

        $this->assertFalse($this->reuse()->rmsLogIsUsable($log));
    }

    #[Test]
    public function it_reuses_a_transcript_that_still_holds_cues(): void
    {
        $log = $this->logWithTranscript([
            'cues' => [['start' => 0.0, 'end' => 4.0, 'text' => 'Good morning.']],
            'duration' => 4.0,
            'source' => 'local_whisper',
        ]);

        $this->assertTrue($this->reuse()->serviceTranscriptIsUsable($log));
    }

    #[Test]
    public function it_will_not_reuse_a_transcript_with_no_cues(): void
    {
        $log = $this->logWithTranscript([
            'cues' => [],
            'duration' => 0.0,
            'source' => 'local_whisper',
        ]);

        $this->assertFalse($this->reuse()->serviceTranscriptIsUsable($log));
    }

    /**
     * A write interrupted mid-flight leaves valid-looking bytes that are not
     * valid JSON. Re-transcribing is the correct answer; adopting it is not.
     */
    #[Test]
    public function it_will_not_reuse_a_truncated_transcript(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();
        $log->putServiceTranscriptPath(self::TranscriptPath);

        Storage::disk('transcripts')->put(
            self::TranscriptPath,
            '{"cues":[{"start":0.0,"end":4.0,"text":"Good mor',
        );

        $this->assertFalse($this->reuse()->serviceTranscriptIsUsable($log->fresh()));
    }

    #[Test]
    public function it_will_not_reuse_a_transcript_whose_file_is_gone(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();
        $log->putServiceTranscriptPath(self::TranscriptPath);

        $this->assertFalse($this->reuse()->serviceTranscriptIsUsable($log->fresh()));
    }

    /** @param array<string, mixed> $transcript */
    private function logWithTranscript(array $transcript): MediaProcessingLog
    {
        $log = MediaProcessingLog::factory()->livestream()->create();
        $log->putServiceTranscriptPath(self::TranscriptPath);

        Storage::disk('transcripts')->put(
            self::TranscriptPath,
            json_encode($transcript, JSON_THROW_ON_ERROR),
        );

        $fresh = $log->fresh();
        $this->assertInstanceOf(MediaProcessingLog::class, $fresh);

        return $fresh;
    }

    private function rmsLog(): string
    {
        return implode("\n", [
            'frame:0    pts_time:0',
            'lavfi.astats.Overall.RMS_level=-35.5',
            'frame:1    pts_time:0.5',
            'lavfi.astats.Overall.RMS_level=-42.3',
        ]);
    }

    private function reuse(): ProcessingArtifactReuse
    {
        return app(ProcessingArtifactReuse::class);
    }
}
