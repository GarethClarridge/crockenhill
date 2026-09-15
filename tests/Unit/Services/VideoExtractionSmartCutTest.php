<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Media\Audio\AudioCompressionService;
use App\Services\Media\Video\VideoExtractionService;
use App\Services\Processing\StorageAdapterHelper;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real FFmpeg cuts measured the way the §4.1b duration censuses measured them.
 *
 * A stream copy started the picture at the next keyframe while the sound started
 * on time (89 song clips opened on a frozen frame, 12 sermons over 3 s), and a
 * re-encode that copied its audio started the sound at the previous keyframe
 * (23 clips carried the preceding item). Every cut must start picture and sound
 * together on the planned time and run for the planned span.
 */
class VideoExtractionSmartCutTest extends TestCase
{
    /** One frame at the fixtures' 25 fps. */
    private const ONE_FRAME = 0.04;

    /** The length tolerance the extraction check allows. */
    private const LENGTH_TOLERANCE = 0.1;

    private VideoExtractionService $service;

    private string $sourceDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_executable('/usr/bin/ffmpeg') || ! is_executable('/usr/bin/ffprobe')) {
            $this->markTestSkipped('ffmpeg and ffprobe are required to measure real cuts.');
        }

        Storage::fake('local');

        Config::set('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg');
        Config::set('media-processing.ffmpeg.ffprobe_path', '/usr/bin/ffprobe');
        Config::set('media-processing.storage.temp_disk', 'local');
        Config::set('media-processing.video_extraction.reencode_above_mbps', 0.0);
        Config::set('media-processing.video_extraction.reencode_preset', 'veryfast');

        $this->sourceDirectory = storage_path('framework/testing/smartcut-sources');
        if (! is_dir($this->sourceDirectory)) {
            mkdir($this->sourceDirectory, 0755, true);
        }

        $this->service = new VideoExtractionService(app(AudioCompressionService::class), app(StorageAdapterHelper::class));
    }

    #[Test]
    public function a_mid_gop_cut_starts_picture_and_sound_together_at_the_planned_time(): void
    {
        $output = $this->cut($this->closedGopSource(), 12.3, 20.7);

        $this->assertStartsTogetherAndRunsFor($output, 8.4);
        $this->assertEqualsWithDelta(210, $this->measure($output)['frames'], 1);
    }

    #[Test]
    public function an_open_gop_source_is_cut_without_undecodable_frames(): void
    {
        $source = $this->source('open-gop.mp4', [
            '-c:v', 'libx264', '-preset', 'veryfast', '-g', '125', '-keyint_min', '125', '-sc_threshold', '0',
            '-bf', '3', '-x264-params', 'open_gop=1', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '128k',
        ]);

        $output = $this->cut($source, 12.3, 30.7);

        $this->assertStartsTogetherAndRunsFor($output, 18.4);
    }

    #[Test]
    public function a_re_encoded_cut_carries_no_sound_from_before_its_start(): void
    {
        Config::set('media-processing.video_extraction.reencode_above_mbps', 0.001);

        $output = $this->cut($this->closedGopSource(), 12.3, 20.7);

        $this->assertStartsTogetherAndRunsFor($output, 8.4);
    }

    #[Test]
    public function a_vp9_source_is_re_encoded_with_picture_and_sound_together(): void
    {
        $source = $this->source('vp9.webm', [
            '-c:v', 'libvpx-vp9', '-deadline', 'realtime', '-cpu-used', '8', '-g', '125', '-b:v', '300k',
            '-c:a', 'libopus',
        ]);

        $output = $this->cut($source, 12.3, 20.7);

        $this->assertStartsTogetherAndRunsFor($output, 8.4);
    }

    #[Test]
    public function joined_spans_keep_picture_and_sound_together_across_the_join(): void
    {
        $relativePath = $this->service->extractConcatenatedSegmentAsFile($this->closedGopSource(), [
            ['start_time' => 12.3, 'end_time' => 20.7],
            ['start_time' => 27.1, 'end_time' => 33.9],
        ]);

        $this->assertStartsTogetherAndRunsFor(Storage::disk('local')->path($relativePath), 15.2, 2 * self::LENGTH_TOLERANCE);
    }

    #[Test]
    public function a_span_past_the_end_of_its_source_keeps_what_the_source_holds(): void
    {
        $output = $this->cut($this->closedGopSource(), 35.0, 45.0);

        $this->assertStartsTogetherAndRunsFor($output, 5.0);
    }

    private function cut(string $source, float $start, float $end): string
    {
        $relativePath = $this->service->extractSegmentAsFile($source, (object) ['start_time' => $start, 'end_time' => $end]);

        return Storage::disk('local')->path($relativePath);
    }

    private function assertStartsTogetherAndRunsFor(string $path, float $expectedSeconds, float $lengthTolerance = self::LENGTH_TOLERANCE): void
    {
        $measured = $this->measure($path);

        $this->assertLessThanOrEqual(
            self::ONE_FRAME,
            abs($measured['video_start'] - $measured['audio_start']),
            'Picture and sound must start together.',
        );
        $this->assertEqualsWithDelta($expectedSeconds, $measured['video_duration'], $lengthTolerance, 'The picture must run for the span.');
        $this->assertEqualsWithDelta($expectedSeconds, $measured['audio_duration'], $lengthTolerance, 'The sound must run for the span.');
        $this->assertSame(0, $measured['decode_errors'], 'Every frame must decode.');
    }

    /**
     * @return array{video_start: float, video_duration: float, audio_start: float, audio_duration: float, frames: int, decode_errors: int}
     */
    private function measure(string $path): array
    {
        $probe = (new Process([
            '/usr/bin/ffprobe', '-v', 'error', '-count_frames',
            '-show_entries', 'stream=codec_type,start_time,duration,nb_read_frames', '-of', 'json', $path,
        ]))->setTimeout(120)->mustRun();

        /** @var array{streams: list<array{codec_type: string, start_time?: string, duration?: string, nb_read_frames?: string}>} $probed */
        $probed = json_decode($probe->getOutput(), true);
        $streams = collect($probed['streams'])->keyBy('codec_type');

        $decode = new Process(['/usr/bin/ffmpeg', '-v', 'error', '-i', $path, '-f', 'null', '-']);
        $decode->setTimeout(120)->run();
        $errors = collect(preg_split('/\R/', trim($decode->getErrorOutput())) ?: [])
            ->filter(fn (string $line): bool => $line !== '' && ! str_contains($line, 'mmco'))
            ->count();

        return [
            'video_start' => (float) ($streams['video']['start_time'] ?? 0),
            'video_duration' => (float) ($streams['video']['duration'] ?? 0),
            'audio_start' => (float) ($streams['audio']['start_time'] ?? 0),
            'audio_duration' => (float) ($streams['audio']['duration'] ?? 0),
            'frames' => (int) ($streams['video']['nb_read_frames'] ?? 0),
            'decode_errors' => $errors,
        ];
    }

    private function closedGopSource(): string
    {
        return $this->source('closed-gop.mp4', [
            '-c:v', 'libx264', '-preset', 'veryfast', '-g', '125', '-keyint_min', '125', '-sc_threshold', '0',
            '-bf', '2', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '128k',
        ]);
    }

    /**
     * A 40 s synthetic recording with a keyframe every 5 s, generated once and
     * shared between runs. It is written aside and renamed, so parallel workers
     * never read a half-written source.
     *
     * @param  list<string>  $encoderArguments
     */
    private function source(string $name, array $encoderArguments): string
    {
        $path = "{$this->sourceDirectory}/{$name}";

        if (is_file($path)) {
            return $path;
        }

        $partial = $path.'.part-'.getmypid().'.'.pathinfo($name, PATHINFO_EXTENSION);

        (new Process([
            '/usr/bin/ffmpeg', '-v', 'error', '-y',
            '-f', 'lavfi', '-i', 'testsrc2=size=320x240:rate=25:duration=40',
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=40',
            ...$encoderArguments,
            $partial,
        ]))->setTimeout(180)->mustRun();

        rename($partial, $path);

        return $path;
    }
}
