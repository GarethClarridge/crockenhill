<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\MediaProcessingLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProveHistoricVideoReencodeFallbackCommandTest extends TestCase
{
    use DatabaseTransactions;

    private string $temporaryDirectory;

    private string $reportPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/historic-reencode-proof-'.Str::random(12);
        mkdir($this->temporaryDirectory, 0700, true);
        $this->reportPath = storage_path('app/private/reencode-proof-test-'.Str::random(12).'.json');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporaryDirectory.'/*') ?: [] as $path) {
            unlink($path);
        }

        @rmdir($this->temporaryDirectory);
        @unlink($this->reportPath);

        parent::tearDown();
    }

    #[Test]
    public function it_proves_the_mismatch_fallback_without_creating_processing_state(): void
    {
        $h264Path = $this->temporaryDirectory.'/h264.mp4';
        $vp9Path = $this->temporaryDirectory.'/vp9.webm';
        $this->generateClip($h264Path, 'libx264', 'aac');
        $this->generateClip($vp9Path, 'libvpx-vp9', 'libopus');

        $this->artisan('historic-import:prove-video-reencode-fallback', [
            '--input' => [$h264Path, $vp9Path],
            '--report' => $this->reportPath,
        ])->assertSuccessful();

        $report = json_decode((string) file_get_contents($this->reportPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('concat_reencoded', $report['result']);
        $this->assertFalse($report['processing_state_created']);
        $this->assertNotSame($report['inputs'][0]['codec_fingerprint'], $report['inputs'][1]['codec_fingerprint']);
        $this->assertSame(0, (int) MediaProcessingLog::query()->count());
    }

    private function generateClip(string $path, string $videoCodec, string $audioCodec): void
    {
        $process = new Process([
            (string) config('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg'),
            '-y',
            '-f', 'lavfi',
            '-i', 'color=c=black:s=320x180:r=25:d=0.5',
            '-f', 'lavfi',
            '-i', 'anullsrc=r=48000:cl=stereo',
            '-shortest',
            '-c:v', $videoCodec,
            '-c:a', $audioCodec,
            $path,
        ]);
        $process->setTimeout(30);
        $process->mustRun();
    }
}
