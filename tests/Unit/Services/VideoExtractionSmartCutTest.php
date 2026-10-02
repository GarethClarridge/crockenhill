<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\VideoProcessingException;
use App\Services\Media\Audio\AudioCompressionService;
use App\Services\Media\Audio\AudioEnhancementService;
use App\Services\Media\Video\SourceAwareMediaTimingChecker;
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
    public function a_span_past_the_end_of_its_source_is_rejected(): void
    {
        $this->expectException(VideoProcessingException::class);
        $this->expectExceptionMessage('outside source');
        $this->cut($this->closedGopSource(), 35.0, 45.0);
    }

    #[Test]
    public function a_smart_cut_shows_the_source_frames_of_its_span(): void
    {
        $source = $this->frameNumberedSource();
        $output = $this->cut($source, 12.3, 30.7);

        $this->assertStartsTogetherAndRunsFor($output, 18.4);
        $this->assertShowsSourceFramesFrom($output, $source, 12.3);
    }

    #[Test]
    public function a_smart_cut_of_a_source_whose_timestamps_do_not_start_at_zero_shows_the_frames_of_its_span(): void
    {
        $source = $this->frameNumberedSource(['-output_ts_offset', '1.4'], 'frame-numbered-offset.mp4');
        $output = $this->cut($source, 12.3, 30.7);

        $this->assertStartsTogetherAndRunsFor($output, 18.4);
        $this->assertShowsSourceFramesFrom($output, $source, 12.3);
    }

    #[Test]
    public function a_smart_cut_past_the_coarse_seek_pad_shows_the_source_frames_of_its_span(): void
    {
        Config::set('media-processing.video_extraction.copy_seek_prefix_seconds', 2.0);

        $source = $this->frameNumberedSource();
        $output = $this->cut($source, 12.3, 30.7);

        $this->assertStartsTogetherAndRunsFor($output, 18.4);
        $this->assertShowsSourceFramesFrom($output, $source, 12.3);
    }

    #[Test]
    public function a_re_encoded_cut_shows_the_source_frames_of_its_span(): void
    {
        Config::set('media-processing.video_extraction.reencode_above_mbps', 0.001);

        $source = $this->frameNumberedSource();
        $output = $this->cut($source, 12.3, 30.7);

        $this->assertShowsSourceFramesFrom($output, $source, 12.3);
    }

    #[Test]
    public function a_smart_cut_of_a_source_without_b_frames_steps_one_frame_at_a_time_across_both_joins(): void
    {
        $source = $this->livestreamLikeSource();
        $output = $this->cut($source, 12.3, 30.7);

        $this->assertFramesStepEvenly($output);
        $this->assertStartsTogetherAndRunsFor($output, 18.4);
        $this->assertShowsSourceFramesFrom($output, $source, 12.3);
    }

    #[Test]
    public function a_smart_cut_of_a_source_timed_in_milliseconds_steps_one_frame_at_a_time(): void
    {
        // 1025 and 1050: Matroska at 30 fps, so frames fall 33, 33 and 34 ms apart, and the
        // re-encoded opening and closing, seeking half a frame early, paired every third frame.
        // 1025 §1374 started 30 ms before a frame, as this span does; the seek then sits half a
        // frame early, where the millisecond times round either way onto the 1/30 s grid.
        $output = $this->cut($this->millisecondTimedSource(), 12.358, 30.7);

        $this->assertFramesStepEvenly($output, 1 / 30);
        $this->assertStartsTogetherAndRunsFor($output, 18.342);
    }

    #[Test]
    public function joined_spans_of_a_source_without_b_frames_step_one_frame_at_a_time_across_every_join(): void
    {
        $relativePath = $this->service->extractConcatenatedSegmentAsFile($this->livestreamLikeSource(), [
            ['start_time' => 12.3, 'end_time' => 20.7],
            ['start_time' => 27.1, 'end_time' => 33.9],
        ]);

        $this->assertFramesStepEvenly(Storage::disk('local')->path($relativePath));
    }

    #[Test]
    public function paired_spans_and_enhancement_preserve_simultaneous_events_through_the_last_seconds(): void
    {
        $source = $this->eventSource();
        $path = $this->service->extractConcatenatedSegmentAsFile($source, [
            ['start_time' => 0.13, 'end_time' => 8.337],
            ['start_time' => 12.17, 'end_time' => 20.037],
        ]);
        $output = Storage::disk('local')->path($path);
        $this->assertEventsTogether($output);

        Config::set('media-processing.audio_enhancement.enabled', true);
        Config::set('media-processing.audio_enhancement.skip_tolerance_lufs', 0);
        $enhanced = app(AudioEnhancementService::class)->enhanceVideo($output, 'paired-events-'.getmypid());
        $this->assertNotNull($enhanced);
        try {
            $this->assertEventsTogether($enhanced);
        } finally {
            unlink($enhanced);
        }
    }

    #[Test]
    public function loudnorm_flush_damage_is_reproduced_and_the_fixed_enhancement_keeps_the_final_events(): void
    {
        $source = $this->source('loudnorm-tail-events.mp4', [
            '-c:v', 'libx264', '-preset', 'veryfast', '-pix_fmt', 'yuv420p', '-c:a', 'aac',
        ], 'color=black:size=160x120:rate=25:duration=192.049,geq=lum=if(between(mod(T\\,1)\\,0.48\\,0.60)\\,235\\,16)',
            'aevalsrc=if(between(mod(t\\,1)\\,0.48\\,0.60)\\,0.6*sin(2*PI*1000*t)\\,0):s=48000:d=192.049');
        config(['media-processing.audio_enhancement.enabled' => true, 'media-processing.audio_enhancement.skip_tolerance_lufs' => 0]);
        $enhancement = app(AudioEnhancementService::class);
        $chain = $enhancement->buildFilterChain($source, 'loudnorm-tail');
        $this->assertNotNull($chain);
        $legacy = $this->sourceDirectory.'/loudnorm-tail-legacy.mp4';
        $legacyChain = str_replace(',asetpts=N/SR/TB', '', $chain);
        (new Process(['/usr/bin/ffmpeg', '-v', 'error', '-y', '-i', $source, '-af', $legacyChain,
            '-c:v', 'copy', '-c:a', 'aac', '-ar', '48000', '-b:a', '128k', $legacy]))->setTimeout(120)->mustRun();
        $checker = app(SourceAwareMediaTimingChecker::class);
        try {
            $checker->check($source, $legacy, [['start_time' => 0.0, 'end_time' => $checker->duration($source)]]);
            $this->fail('The pre-fix loudnorm chain must reproduce its late audio hole.');
        } catch (VideoProcessingException $exception) {
            $this->assertStringContainsString('introduced audio discontinuity', $exception->getMessage());
        }
        $fixed = $enhancement->enhanceVideo($source, 'loudnorm-tail-fixed-'.getmypid());
        $this->assertNotNull($fixed);
        try {
            $this->assertEventsTogether($fixed);
            $this->assertGreaterThan(180, count($this->eventOffsets($fixed)));
        } finally {
            unlink($fixed);
        }
    }

    #[Test]
    public function regularly_timed_shifted_audio_is_a_negative_control_for_event_alignment(): void
    {
        $source = $this->eventSource();
        $shifted = $this->sourceDirectory.'/shifted-events.mp4';
        (new Process(['/usr/bin/ffmpeg', '-v', 'error', '-y', '-i', $source, '-af', 'adelay=200:all=1', '-c:v', 'copy', '-c:a', 'aac', $shifted]))->setTimeout(120)->mustRun();

        $offsets = $this->eventOffsets($shifted);
        $this->assertNotEmpty($offsets);
        $this->assertGreaterThan(0.15, max(array_map(abs(...), $offsets)));
    }

    #[Test]
    public function the_source_aware_checker_blocks_an_introduced_audio_hole(): void
    {
        $source = $this->eventSource();
        $damaged = $this->sourceDirectory.'/introduced-audio-hole.mp4';
        (new Process(['/usr/bin/ffmpeg', '-v', 'error', '-y', '-i', $source,
            '-af', 'asetpts=PTS+if(gte(T\\,20)\\,0.08/TB\\,0)', '-c:v', 'copy', '-c:a', 'aac', $damaged]))->setTimeout(120)->mustRun();
        $this->expectException(VideoProcessingException::class);
        $this->expectExceptionMessage('introduced audio discontinuity');
        app(SourceAwareMediaTimingChecker::class)->check($source, $damaged, [['start_time' => 0.0, 'end_time' => 40.0]]);
    }

    #[Test]
    public function the_checker_accepts_an_irregular_last_frame_already_present_in_the_source(): void
    {
        $source = $this->source('irregular-last-frame.mp4', [
            '-vf', 'setpts=PTS+if(gte(N\\,999)\\,0.08/TB\\,0)', '-fps_mode', 'vfr',
            '-c:v', 'libx264', '-preset', 'veryfast', '-c:a', 'aac',
        ]);
        $checker = app(SourceAwareMediaTimingChecker::class);
        $report = $checker->check($source, $source, [['start_time' => 0.0, 'end_time' => $checker->duration($source)]]);
        $this->assertTrue($report['passed']);
        $this->assertGreaterThan(0, $report['source_anomalies']);
    }

    #[Test]
    public function an_audio_codec_change_does_not_hide_a_small_introduced_timing_hole(): void
    {
        $source = $this->source('mp3-track.mp4', [
            '-c:v', 'libx264', '-preset', 'veryfast', '-pix_fmt', 'yuv420p', '-c:a', 'libmp3lame', '-ar', '44100',
        ]);
        $damaged = $this->sourceDirectory.'/mp3-track-damaged.mp4';
        (new Process(['/usr/bin/ffmpeg', '-v', 'error', '-y', '-i', $source,
            '-af', 'asetpts=PTS+if(gte(T\\,20)\\,0.009/TB\\,0)', '-c:v', 'copy', '-c:a', 'aac', '-ar', '48000', $damaged]))->setTimeout(120)->mustRun();
        $this->expectException(VideoProcessingException::class);
        $this->expectExceptionMessage('introduced audio discontinuity');
        app(SourceAwareMediaTimingChecker::class)->check($source, $damaged, [['start_time' => 0.0, 'end_time' => 40.0]]);
    }

    private function eventSource(): string
    {
        return $this->source('simultaneous-events.mp4', [
            '-c:v', 'libx264', '-preset', 'veryfast', '-g', '125', '-pix_fmt', 'yuv420p', '-c:a', 'aac',
        ], 'color=black:size=160x120:rate=25:duration=40,geq=lum=if(between(mod(T\\,1)\\,0.48\\,0.60)\\,235\\,16)',
            'aevalsrc=if(between(mod(t\\,1)\\,0.48\\,0.60)\\,0.6*sin(2*PI*1000*t)\\,0):s=48000:d=40');
    }

    private function assertEventsTogether(string $path): void
    {
        $offsets = $this->eventOffsets($path);
        $this->assertGreaterThan(10, count($offsets), 'Include events in the final seconds.');
        // One 25 fps picture plus one 48 kHz AAC frame and one sample. No accumulated allowance.
        $this->assertLessThanOrEqual(1 / 25 + 1024 / 48000 + 1 / 48000, max(array_map(abs(...), $offsets)));
    }

    /** @return list<float> */
    private function eventOffsets(string $path): array
    {
        $video = (new Process(['/usr/bin/ffmpeg', '-v', 'error', '-i', $path, '-vf', 'scale=1:1,format=gray', '-f', 'rawvideo', '-']))->setTimeout(120)->mustRun()->getOutput();
        $audio = (new Process(['/usr/bin/ffmpeg', '-v', 'error', '-i', $path, '-vn', '-ac', '1', '-ar', '48000', '-f', 'f32le', '-']))->setTimeout(120)->mustRun()->getOutput();
        $pictureEvents = [];
        $wasBright = false;
        foreach (str_split($video) as $index => $pixel) {
            $bright = ord($pixel) > 128;
            if ($bright && ! $wasBright) {
                $pictureEvents[] = $index / 25;
            }
            $wasBright = $bright;
        }
        $soundEvents = [];
        $wasLoud = false;
        foreach (str_split($audio, 480 * 4) as $index => $window) {
            $samples = unpack('g*', $window) ?: [];
            $power = array_sum(array_map(fn (float $sample): float => $sample * $sample, $samples)) / max(count($samples), 1);
            $loud = $power > 0.002;
            if ($loud && ! $wasLoud) {
                $soundEvents[] = $index * 0.01;
            }
            $wasLoud = $loud;
        }
        $this->assertCount(count($pictureEvents), $soundEvents, 'Every flash must have its sound.');

        return array_map(fn (float $picture, float $sound): float => $sound - $picture, $pictureEvents, $soundEvents);
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

    /**
     * Neither length nor the frame shown can see a join that repeats or skips a
     * timestamp: 50 of canary 6's 75 cuts held two frames' worth of gap where the
     * copied frames met the re-encoded closing, and a repeated timestamp where the
     * opening met them, while running the span's length. So require every picture
     * to follow the one before by exactly one frame.
     */
    private function assertFramesStepEvenly(string $path, float $frameSeconds = self::ONE_FRAME): void
    {
        $probe = (new Process([
            '/usr/bin/ffprobe', '-v', 'error', '-select_streams', 'v:0',
            '-show_entries', 'packet=pts_time', '-of', 'csv=p=0', $path,
        ]))->setTimeout(120)->mustRun();

        $times = collect(preg_split('/\R/', trim($probe->getOutput())) ?: [])
            ->filter(fn (string $line): bool => is_numeric($line))
            ->map(fn (string $line): float => (float) $line)
            ->sort()
            ->values();

        $uneven = $times->sliding(2)
            ->map(fn ($pair): float => round($pair->last() - $pair->first(), 4))
            ->filter(fn (float $step): bool => abs($step - $frameSeconds) > 0.002);

        $this->assertTrue($uneven->isEmpty(), sprintf(
            '%d of %d steps between pictures are not one frame: %s.',
            $uneven->count(),
            max($times->count() - 1, 0),
            $uneven->take(5)->map(fn (float $step, int $index): string => sprintf('%.3f s after %.3f s', $step, $times[$index]))->implode(', '),
        ));
    }

    /**
     * Duration and start times cannot see a cut that shows the wrong picture: a
     * copied middle one GOP late runs exactly as long. So read the frame number
     * each picture carries and require every frame to be the source's own frame
     * for its time, within the one frame an exact seek may round to.
     */
    private function assertShowsSourceFramesFrom(string $path, string $source, float $start): void
    {
        $shown = $this->shownFrameNumbers($path);

        // A span counts from the file's first timestamp, which the sound may hold
        // ahead of the picture, so frame 0 shows that far into the span.
        $starts = (new Process([
            '/usr/bin/ffprobe', '-v', 'error', '-select_streams', 'v:0',
            '-show_entries', 'format=start_time:stream=start_time', '-of', 'json', $source,
        ]))->setTimeout(60)->mustRun();
        /** @var array{format: array{start_time: string}, streams: list<array{start_time: string}>} $probed */
        $probed = json_decode($starts->getOutput(), true);
        $pictureLead = (float) $probed['streams'][0]['start_time'] - (float) $probed['format']['start_time'];

        $firstFrame = (int) ceil(($start - $pictureLead) * 25 - 0.001);

        $this->assertNotEmpty($shown, 'The cut must show frames.');

        $misplaced = collect($shown)
            ->map(fn (int $frameNumber, int $index): int => $frameNumber - ($firstFrame + $index))
            ->filter(fn (int $offset): bool => abs($offset) > 1);

        $this->assertTrue($misplaced->isEmpty(), sprintf(
            '%d of %d frames show the wrong source frame; the first, output frame %d, shows source frame %d where %d was due.',
            $misplaced->count(),
            count($shown),
            $misplaced->keys()->first() ?? 0,
            $shown[$misplaced->keys()->first() ?? 0],
            $firstFrame + ($misplaced->keys()->first() ?? 0),
        ));
    }

    /**
     * @return list<int>
     */
    private function shownFrameNumbers(string $path): array
    {
        $directory = dirname($path);
        $lowFile = "{$directory}/frame-numbers-low.txt";
        $highFile = "{$directory}/frame-numbers-high.txt";

        (new Process([
            '/usr/bin/ffmpeg', '-v', 'error', '-i', $path,
            '-filter_complex',
            "[0:v]format=gray,crop=iw/2-8:ih-8:4:4,signalstats,metadata=print:key=lavfi.signalstats.YAVG:file={$lowFile}[low];"
            ."[0:v]format=gray,crop=iw/2-8:ih-8:iw/2+4:4,signalstats,metadata=print:key=lavfi.signalstats.YAVG:file={$highFile}[high]",
            '-map', '[low]', '-f', 'null', '-',
            '-map', '[high]', '-f', 'null', '-',
        ]))->setTimeout(120)->mustRun();

        $levels = fn (string $file): array => collect(file($file) ?: [])
            ->filter(fn (string $line): bool => str_contains($line, 'YAVG='))
            ->map(fn (string $line): int => (int) round(((float) substr($line, strpos($line, '=') + 1) - 16) / 4))
            ->values()
            ->all();

        $low = $levels($lowFile);
        $high = $levels($highFile);

        unlink($lowFile);
        unlink($highFile);

        return array_map(fn (int $lowLevel, int $highLevel): int => $highLevel * 50 + $lowLevel, $low, $high);
    }

    /**
     * A 40 s recording whose every picture carries its own frame number: the left
     * half's brightness is the number modulo 50 and the right half's the count of
     * fifties, four levels apart so compression cannot move one to the next.
     *
     * @param  list<string>  $extraArguments
     */
    private function frameNumberedSource(array $extraArguments = [], string $name = 'frame-numbered.mp4'): string
    {
        return $this->source($name, [
            '-c:v', 'libx264', '-preset', 'veryfast', '-g', '125', '-keyint_min', '125', '-sc_threshold', '0',
            '-bf', '2', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '128k',
            ...$extraArguments,
        ], 'color=c=black:size=160x120:rate=25:duration=40,format=gray,'
            .'geq=lum=if(lt(X\,W/2)\,16+4*mod(N\,50)\,16+4*mod(floor(N/50)\,50))');
    }

    /**
     * The frame-numbered recording as the livestream encoder writes it: no B-frames,
     * so the copied frames carry no decode delay while the re-encoded opening and
     * closing do, and a keyframe every 16 frames.
     */
    private function livestreamLikeSource(): string
    {
        return $this->frameNumberedSource(['-bf', '0', '-g', '16', '-keyint_min', '16'], 'frame-numbered-no-b-frames.mp4');
    }

    /**
     * A 30 fps recording in Matroska, whose millisecond clock cannot hold a frame's length,
     * starting 21 ms in as the livestream recordings do, so no frame sits on the 1/30 s grid.
     */
    private function millisecondTimedSource(): string
    {
        return $this->source('thirty-fps-offset.mkv', [
            '-c:v', 'libx264', '-preset', 'veryfast', '-g', '16', '-keyint_min', '16', '-sc_threshold', '0',
            '-bf', '1', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '128k', '-output_ts_offset', '0.021',
        ], 'testsrc2=size=320x240:rate=30:duration=40');
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
    private function source(string $name, array $encoderArguments, string $picture = 'testsrc2=size=320x240:rate=25:duration=40', string $sound = 'sine=frequency=440:sample_rate=48000:duration=40'): string
    {
        $path = "{$this->sourceDirectory}/{$name}";

        if (is_file($path)) {
            return $path;
        }

        $partial = $path.'.part-'.getmypid().'.'.pathinfo($name, PATHINFO_EXTENSION);

        (new Process([
            '/usr/bin/ffmpeg', '-v', 'error', '-y',
            '-f', 'lavfi', '-i', $picture,
            '-f', 'lavfi', '-i', $sound,
            ...$encoderArguments,
            $partial,
        ]))->setTimeout(180)->mustRun();

        rename($partial, $path);

        return $path;
    }
}
