<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Media\ExtractedMediaDurationProbe;
use App\Services\Media\SourceMediaDuration;
use App\Services\Processing\StorageAdapterHelper;
use FFMpeg\FFProbe;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SourceMediaDurationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('media-processing.ffmpeg.ffprobe_path', '/usr/bin/ffprobe');
        $this->directory = sys_get_temp_dir().'/source-media-duration-'.uniqid();
        mkdir($this->directory);
        $this->app->instance(ExtractedMediaDurationProbe::class, new ExtractedMediaDurationProbe(
            new StorageAdapterHelper,
            FFProbe::create(['ffprobe.binaries' => '/usr/bin/ffprobe']),
        ));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory.'/*') ?: []);
        rmdir($this->directory);

        parent::tearDown();
    }

    #[Test]
    public function a_container_duration_is_read_from_the_header(): void
    {
        $path = $this->encode('indexed.webm', ['-f', 'lavfi', '-i', 'testsrc=d=2:s=64x48:r=10', '-c:v', 'libvpx'], $this->directory.'/indexed.webm');

        $this->assertEqualsWithDelta(2.0, app(SourceMediaDuration::class)->seconds($path), 0.05);
    }

    /** Re-import 18432 (11-02 round): a WebM written without an index declares no duration. */
    #[Test]
    public function a_webm_that_declares_no_duration_is_measured_from_its_packets(): void
    {
        $path = $this->encode('unindexed.webm', ['-f', 'lavfi', '-i', 'testsrc=d=2:s=64x48:r=10', '-c:v', 'libvpx', '-f', 'webm'], 'pipe:1');

        $this->assertEqualsWithDelta(2.0, app(SourceMediaDuration::class)->seconds($path), 0.000001);
    }

    #[Test]
    public function a_source_that_cannot_be_measured_has_no_duration_rather_than_zero(): void
    {
        $path = $this->directory.'/unreadable.webm';
        file_put_contents($path, 'not a recording');

        $this->assertNull(app(SourceMediaDuration::class)->seconds($path));
    }

    /** @param  list<string>  $input */
    private function encode(string $name, array $input, string $output): string
    {
        $process = new Process(['/usr/bin/ffmpeg', '-v', 'error', '-y', ...$input, $output]);
        $process->mustRun();
        $path = $this->directory.'/'.$name;

        if ($output === 'pipe:1') {
            file_put_contents($path, $process->getOutput());
        }

        return $path;
    }
}
