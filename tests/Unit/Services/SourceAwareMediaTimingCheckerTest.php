<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\VideoProcessingException;
use App\Services\Media\Video\SourceAwareMediaTimingChecker;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SourceAwareMediaTimingCheckerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/cut-timing-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->directory);
        $probe = $this->directory.'/ffprobe';
        File::put($probe, <<<'PROBE'
#!/usr/bin/env php
<?php
$damaged = str_contains(end($argv), 'damaged');
$packets = [];
for ($i = 0; $i < 30; $i++) {
    $packets[] = ['stream_index' => 0, 'pts_time' => (string) ($i / 30 + ($damaged && $i >= 2 ? 0.016 : 0)), 'duration_time' => (string) (1 / 30)];
    $packets[] = ['stream_index' => 1, 'pts_time' => (string) ($i / 30), 'duration_time' => (string) (1 / 30)];
}
echo json_encode(['format' => ['start_time' => '0', 'duration' => '1'], 'streams' => [['index' => 0, 'codec_type' => 'video', 'avg_frame_rate' => '30/1'], ['index' => 1, 'codec_type' => 'audio']], 'packets' => $packets]);
PROBE);
        chmod($probe, 0755);
        config(['media-processing.ffmpeg.ffprobe_path' => $probe]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    #[Test]
    public function it_rejects_the_documented_sixteen_millisecond_picture_jump(): void
    {
        $this->expectException(VideoProcessingException::class);
        $this->expectExceptionMessage('introduced video discontinuity');
        app(SourceAwareMediaTimingChecker::class)->check($this->directory.'/source', $this->directory.'/damaged', [['start_time' => 0.0, 'end_time' => 1.0]]);
    }

    #[Test]
    public function it_accepts_the_same_picture_irregularity_when_present_in_the_source(): void
    {
        $report = app(SourceAwareMediaTimingChecker::class)->check($this->directory.'/damaged-source', $this->directory.'/damaged-output', [['start_time' => 0.0, 'end_time' => 1.0]]);
        $this->assertTrue($report['passed']);
        $this->assertSame(1, $report['source_anomalies']);
    }

    #[Test]
    public function one_frame_of_picture_rounding_is_allowed_only_at_a_selected_join(): void
    {
        $report = app(SourceAwareMediaTimingChecker::class)->check($this->directory.'/source', $this->directory.'/damaged', [
            ['start_time' => 0.0, 'end_time' => 2 / 30],
            ['start_time' => 2 / 30, 'end_time' => 1.0],
        ]);
        $this->assertTrue($report['passed']);
    }
}
