<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\VideoProcessingException;
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
    public function a_cut_that_defers_its_render_copies_a_source_above_the_bitrate_threshold(): void
    {
        Config::set('media-processing.video_extraction.reencode_above_mbps', 0.001);

        $source = $this->frameNumberedSource();
        $output = $this->cut($source, 12.3, 30.7, deferRender: true);

        $this->assertStartsTogetherAndRunsFor($output, 18.4);
        $this->assertShowsSourceFramesFrom($output, $source, 12.3);

        $detailed = $this->closedGopSource();
        $this->assertGreaterThan(
            0.6,
            $this->shareOfPicturesCopiedFrom($this->cut($detailed, 12.3, 30.7, deferRender: true), $detailed),
            'The middle must be copied, not re-encoded.',
        );
    }

    #[Test]
    public function a_cut_that_defers_its_render_still_re_encodes_an_undeliverable_codec(): void
    {
        $source = $this->source('vp9.webm', [
            '-c:v', 'libvpx-vp9', '-deadline', 'realtime', '-cpu-used', '8', '-g', '125', '-b:v', '300k',
            '-c:a', 'libopus',
        ]);

        $output = $this->cut($source, 12.3, 20.7, deferRender: true);

        $this->assertStartsTogetherAndRunsFor($output, 8.4);
        $this->assertSame('h264', $this->videoCodec($output));
    }

    #[Test]
    public function joined_spans_that_defer_their_render_are_copied(): void
    {
        Config::set('media-processing.video_extraction.reencode_above_mbps', 0.001);

        $source = $this->closedGopSource();
        $relativePath = $this->service->extractConcatenatedSegmentAsFile($source, [
            ['start_time' => 12.3, 'end_time' => 20.7],
            ['start_time' => 27.1, 'end_time' => 33.9],
        ], deferRender: true);
        $output = Storage::disk('local')->path($relativePath);

        $this->assertStartsTogetherAndRunsFor($output, 15.2, 2 * self::LENGTH_TOLERANCE);
        // Only 15-20 s holds a whole GOP to copy (keyframes every 5 s): 5 of 15.2 s.
        $this->assertGreaterThan(0.3, $this->shareOfPicturesCopiedFrom($output, $source));
    }

    #[Test]
    public function rendering_a_cut_above_the_threshold_re_encodes_its_picture_and_keeps_its_sound(): void
    {
        $source = $this->closedGopSource();
        $cut = $this->cut($source, 12.3, 30.7);
        $copiedBefore = $this->shareOfPicturesCopiedFrom($cut, $source);
        $before = $this->measure($cut);
        $soundBefore = $this->audioPacketHash($cut);

        // Just under the cut's own bitrate, which a CRF 40 render falls far below.
        Config::set('media-processing.video_extraction.reencode_above_mbps', 0.9 * $this->bitrateMbps($cut));
        Config::set('media-processing.video_extraction.reencode_crf', 40);

        $this->assertTrue($this->service->renderForDelivery($cut));

        $after = $this->measure($cut);
        $this->assertSame($before['frames'], $after['frames'], 'Every frame must survive the render.');
        $this->assertStartsTogetherAndRunsFor($cut, 18.4);
        $this->assertSame($soundBefore, $this->audioPacketHash($cut), 'The sound must be copied untouched.');
        $this->assertGreaterThan(0.6, $copiedBefore);
        $this->assertLessThan(0.1, $this->shareOfPicturesCopiedFrom($cut, $source), 'The picture must be re-encoded.');
    }

    #[Test]
    public function rendering_a_cut_at_or_below_the_threshold_leaves_it_alone(): void
    {
        $cut = $this->cut($this->closedGopSource(), 12.3, 20.7);
        $hash = hash_file('sha256', $cut);

        Config::set('media-processing.video_extraction.reencode_above_mbps', 1000.0);

        $this->assertFalse($this->service->renderForDelivery($cut));
        $this->assertSame($hash, hash_file('sha256', $cut));
    }

    #[Test]
    public function a_render_that_stays_above_the_threshold_is_refused_and_leaves_the_cut_in_place(): void
    {
        $cut = $this->cut($this->closedGopSource(), 12.3, 20.7);
        $hash = hash_file('sha256', $cut);

        // Lossless output is larger than the cut, so it cannot come under a
        // threshold the cut itself only just exceeds.
        Config::set('media-processing.video_extraction.reencode_above_mbps', 0.9 * $this->bitrateMbps($cut));
        Config::set('media-processing.video_extraction.reencode_crf', 0);

        try {
            $this->service->renderForDelivery($cut);
            $this->fail('A render still above the threshold must be refused.');
        } catch (VideoProcessingException $exception) {
            $this->assertStringContainsString('above', $exception->getMessage());
        }

        $this->assertSame($hash, hash_file('sha256', $cut));
        $this->assertSame([], glob(dirname($cut).'/cut-*') ?: [], 'The render must leave no work files behind.');
    }

    /**
     * An unreadable bitrate says nothing about whether the cut is deliverable, so it must not
     * read as "already rendered": the job would clear the release gate on a failed probe.
     */
    #[Test]
    public function a_render_whose_bitrate_cannot_be_read_is_refused(): void
    {
        $cut = $this->cut($this->closedGopSource(), 12.3, 20.7);
        $hash = hash_file('sha256', $cut);

        Config::set('media-processing.video_extraction.reencode_above_mbps', 0.001);
        Config::set('media-processing.ffmpeg.ffprobe_path', '/nonexistent/ffprobe');

        $this->expectException(VideoProcessingException::class);
        $this->expectExceptionMessage('bitrate');

        try {
            $this->service->renderForDelivery($cut);
        } finally {
            $this->assertSame($hash, hash_file('sha256', $cut));
        }
    }

    /**
     * Two failed sound digests are equal (both absent), which proves nothing about the sound.
     */
    #[Test]
    public function a_render_whose_sound_cannot_be_verified_is_refused(): void
    {
        $cut = $this->cut($this->closedGopSource(), 12.3, 20.7);
        $hash = hash_file('sha256', $cut);

        Config::set('media-processing.video_extraction.reencode_above_mbps', 0.9 * $this->bitrateMbps($cut));
        Config::set('media-processing.video_extraction.reencode_crf', 40);

        $service = new class(app(AudioCompressionService::class), app(StorageAdapterHelper::class)) extends VideoExtractionService
        {
            protected function audioPacketHash(string $path): ?string
            {
                return null;
            }
        };

        try {
            $service->renderForDelivery($cut);
            $this->fail('A render whose sound cannot be verified must be refused.');
        } catch (VideoProcessingException $exception) {
            $this->assertStringContainsString('sound', $exception->getMessage());
        }

        $this->assertSame($hash, hash_file('sha256', $cut));
    }

    private function cut(string $source, float $start, float $end, bool $deferRender = false): string
    {
        $relativePath = $this->service->extractSegmentAsFile(
            $source,
            (object) ['start_time' => $start, 'end_time' => $end],
            deferRender: $deferRender,
        );

        return Storage::disk('local')->path($relativePath);
    }

    /**
     * How many of a cut's pictures decode to exactly a picture of its source.
     *
     * A copied frame decodes to its source frame bit for bit; a lossy re-encode of
     * a detailed picture does not. Compared as a multiset, so the measure does not
     * depend on where the copied run starts. Packet bytes cannot be compared: the
     * smart cut's MPEG-TS join reframes every packet it copies.
     */
    private function shareOfPicturesCopiedFrom(string $path, string $source): float
    {
        $cutPictures = $this->pictureHashes($path);
        $sourcePictures = array_count_values($this->pictureHashes($source));

        $copied = 0;
        foreach ($cutPictures as $picture) {
            if (($sourcePictures[$picture] ?? 0) > 0) {
                $sourcePictures[$picture]--;
                $copied++;
            }
        }

        return $cutPictures === [] ? 0.0 : $copied / count($cutPictures);
    }

    /**
     * @return list<string>
     */
    private function pictureHashes(string $path): array
    {
        $probe = (new Process([
            '/usr/bin/ffmpeg', '-v', 'error', '-i', $path, '-map', '0:v:0', '-f', 'framemd5', '-',
        ]))->setTimeout(120)->mustRun();

        return collect(preg_split('/\R/', trim($probe->getOutput())) ?: [])
            ->reject(fn (string $line): bool => $line === '' || str_starts_with($line, '#'))
            ->map(fn (string $line): string => trim((string) last(explode(',', $line))))
            ->values()
            ->all();
    }

    private function audioPacketHash(string $path): string
    {
        return trim((new Process([
            '/usr/bin/ffmpeg', '-v', 'error', '-i', $path, '-map', '0:a:0', '-c', 'copy', '-f', 'hash', '-hash', 'sha256', '-',
        ]))->setTimeout(120)->mustRun()->getOutput());
    }

    private function bitrateMbps(string $path): float
    {
        return (float) trim((new Process([
            '/usr/bin/ffprobe', '-v', 'error', '-show_entries', 'format=bit_rate', '-of', 'default=nw=1:nk=1', $path,
        ]))->setTimeout(60)->mustRun()->getOutput()) / 1_000_000;
    }

    private function videoCodec(string $path): string
    {
        return trim((new Process([
            '/usr/bin/ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=codec_name', '-of', 'default=nw=1:nk=1', $path,
        ]))->setTimeout(60)->mustRun()->getOutput());
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
    private function source(string $name, array $encoderArguments, string $picture = 'testsrc2=size=320x240:rate=25:duration=40'): string
    {
        $path = "{$this->sourceDirectory}/{$name}";

        if (is_file($path)) {
            return $path;
        }

        $partial = $path.'.part-'.getmypid().'.'.pathinfo($name, PATHINFO_EXTENSION);

        (new Process([
            '/usr/bin/ffmpeg', '-v', 'error', '-y',
            '-f', 'lavfi', '-i', $picture,
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=40',
            ...$encoderArguments,
            $partial,
        ]))->setTimeout(180)->mustRun();

        rename($partial, $path);

        return $path;
    }
}
