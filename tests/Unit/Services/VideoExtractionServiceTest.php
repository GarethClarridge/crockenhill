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
use Tests\TestCase;

class VideoExtractionServiceTest extends TestCase
{
    private VideoExtractionService $service;

    private StorageAdapterHelper $storageHelper;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        Config::set('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg');
        Config::set('media-processing.ffmpeg.ffprobe_path', '/usr/bin/ffprobe');
        Config::set('media-processing.storage.temp_disk', 'local');
        Config::set('media-processing.storage.sermon_disk', 'public');
        Config::set('media-processing.storage.paths.audio', 'sermons/audio');
        Config::set('media-processing.audio_extraction.transcription_optimized', [
            'bitrate' => 48,
            'sample_rate' => 16000,
            'channels' => 1,
            'max_file_size' => 25 * 1024 * 1024,
        ]);
        Config::set('media-processing.audio_extraction.fallback_compression', [
            'bitrate' => 32,
            'channels' => 1,
        ]);
        Config::set('media-processing.s3_processing', [
            'retry_attempts' => 3,
            'retry_delay' => 1,
            'upload_timeout' => 300,
        ]);

        $this->storageHelper = app(StorageAdapterHelper::class);
        $this->service = new VideoExtractionService(app(AudioCompressionService::class), $this->storageHelper);
    }

    // ---- Constructor and instantiation ----

    #[Test]
    public function it_can_be_instantiated_in_test_environment(): void
    {
        $this->assertInstanceOf(VideoExtractionService::class, $this->service);
    }

    // ---- extractSegment routing tests ----
    // Note: extractSegment, extractSegmentAsFile, and extractSegmentAsUpload
    // require real FFmpeg binaries. We test the logic that doesn't need FFmpeg.

    // ---- Output existence check uses disk-relative paths ----

    #[Test]
    public function it_recognises_the_output_file_created_by_a_successful_stream_copy(): void
    {
        $this->stubFfmpegAndFfprobe(2_600_000);

        $relativePath = $this->service->extractSegmentAsFile(
            '/tmp/input.mp4',
            (object) ['start_time' => 1.0, 'end_time' => 5.0]
        );

        // Regression: the existence check used to pass the absolute path to
        // Storage::exists(), which expects a disk-relative path, so successful
        // extractions were reported as "Output file was not created".
        $this->assertTrue(Storage::disk('local')->exists($relativePath));
        $this->assertSame('fake-video', Storage::disk('local')->get($relativePath));

        unlink(storage_path('framework/testing/ffmpeg-stub.sh'));
    }

    // ---- Segment time property access ----

    #[Test]
    public function it_reads_camel_case_segment_properties(): void
    {
        $segment = (object) ['startTime' => 100, 'endTime' => 200];

        $startTime = $segment->startTime ?? $segment->start_time ?? 0;
        $endTime = $segment->endTime ?? $segment->end_time ?? 0;

        $this->assertEquals(100, $startTime);
        $this->assertEquals(200, $endTime);
    }

    #[Test]
    public function it_reads_snake_case_segment_properties(): void
    {
        $segment = (object) ['start_time' => 100, 'end_time' => 200];

        $startTime = $segment->startTime ?? $segment->start_time ?? 0;
        $endTime = $segment->endTime ?? $segment->end_time ?? 0;

        $this->assertEquals(100, $startTime);
        $this->assertEquals(200, $endTime);
    }

    #[Test]
    public function it_rejects_invalid_segment_times_for_upload_extraction(): void
    {
        // start >= end should throw
        $segment = (object) ['startTime' => 200, 'endTime' => 100];

        $this->expectException(VideoProcessingException::class);
        $this->expectExceptionMessage('Invalid segment times');

        $this->service->extractSegmentAsUpload('/nonexistent/video.mp4', $segment);
    }

    #[Test]
    public function it_rejects_equal_start_and_end_times_for_upload_extraction(): void
    {
        $segment = (object) ['startTime' => 100, 'endTime' => 100];

        $this->expectException(VideoProcessingException::class);
        $this->expectExceptionMessage('Invalid segment times');

        $this->service->extractSegmentAsUpload('/nonexistent/video.mp4', $segment);
    }

    // ---- extractAudio behavior (indirectly testing getProcessingOutputPath, fileExists, etc.) ----

    #[Test]
    public function extract_audio_uses_correct_paths_for_local_disk(): void
    {
        // Use a real-ish audio file path
        $inputVideo = '/tmp/test_video.mp4';
        $segment = (object) ['start_time' => 0, 'end_time' => 10];

        // We can't easily run extractAudio because it calls requireFfmpeg()
        // which returns null in tests, causing a crash if we try to use it.
        // But we can verify the storage helper interaction.
        $mockHelper = $this->createMock(StorageAdapterHelper::class);
        $mockHelper->expects($this->once())
            ->method('getProcessingOutputPath')
            ->with(
                $this->stringContains('_sermon.mp3'),
                'sermons/audio',
                'public',
                'local',
                'temp/audio_extraction'
            )
            ->willReturn([
                'processing_path' => '/app/storage/app/public/sermons/audio/test.mp3',
                'permanent_path' => 'sermons/audio/test.mp3',
                'use_temp_processing' => false,
            ]);

        $service = new VideoExtractionService(app(AudioCompressionService::class), $mockHelper);

        try {
            $service->extractAudio($inputVideo, $segment);
        } catch (VideoProcessingException $e) {
            $this->assertStringContainsString('FFmpeg is unavailable', $e->getMessage());
        }
    }

    #[Test]
    public function extract_audio_uses_temp_path_for_s3_disk(): void
    {
        Config::set('filesystems.disks.s3_disk', ['driver' => 's3']);
        Config::set('media-processing.storage.sermon_disk', 's3_disk');

        $mockHelper = $this->createMock(StorageAdapterHelper::class);
        $mockHelper->expects($this->once())
            ->method('getProcessingOutputPath')
            ->with(
                $this->anything(),
                'sermons/audio',
                's3_disk',
                'local',
                'temp/audio_extraction'
            )
            ->willReturn([
                'processing_path' => '/app/storage/app/temp/audio_extraction/test.mp3',
                'permanent_path' => 'sermons/audio/test.mp3',
                'use_temp_processing' => true,
            ]);

        $service = new VideoExtractionService(app(AudioCompressionService::class), $mockHelper);

        try {
            $service->extractAudio('/tmp/video.mp4', (object) ['start_time' => 0, 'end_time' => 10]);
        } catch (VideoProcessingException $e) {
            $this->assertStringContainsString('FFmpeg is unavailable', $e->getMessage());
        }
    }

    // ---- Re-encode threshold (media-processing.video_extraction) ----

    /**
     * Stands in for ffmpeg/ffprobe. `$probeBitrate` is what ffprobe reports for
     * `format=bit_rate` — pass 0 for the `N/A` the historic webm corpus actually
     * returns; `$videoCodec` and `$audioCodec` answer the `stream=codec_name`
     * queries, and an empty string makes that stream's codec unreadable. The
     * ffmpeg stub records the argv it was called with so a test can assert which
     * branch ran, then writes the output file and exits 0.
     */
    private function stubFfmpegAndFfprobe(
        int $probeBitrate,
        string $videoCodec = 'h264',
        string $audioCodec = 'aac',
        ?string $probeCallLog = null,
    ): string {
        $argvLog = storage_path('framework/testing/ffmpeg-argv.log');
        @unlink($argvLog);

        $ffmpegStub = storage_path('framework/testing/ffmpeg-stub.sh');
        file_put_contents(
            $ffmpegStub,
            "#!/bin/sh\n"
            .'echo "$@" >> '.escapeshellarg($argvLog)."\n"
            .'for last in "$@"; do :; done'."\n"
            .'printf \'fake-video\' > "$last"'."\n"
        );
        chmod($ffmpegStub, 0755);

        $ffprobeStub = storage_path('framework/testing/ffprobe-stub.php');
        $probeCode = <<<'PHP'
#!/usr/bin/env php
<?php
$path = $argv[count($argv) - 1];
$source = str_contains($path, 'input.') || str_contains($path, 'probe-source');
$duration = 10000.0;
if (! $source) {
    preg_match('/-t ([0-9.]+)/', file_get_contents(ARGV_LOG), $matches);
    $duration = (float) ($matches[1] ?? 4);
}
$packets = [];
foreach ([0 => 1 / 30, 1 => 1024 / 48000] as $stream => $step) {
    $count = $source ? 2 : (int) ceil($duration / $step);
    for ($index = 0; $index < $count; $index++) {
        $time = $source && $index === 1 ? $duration - $step : $index * $step;
        $packets[] = ['stream_index' => $stream, 'pts_time' => (string) $time, 'duration_time' => (string) min($step, $duration - $time)];
    }
}
if (PROBE_LOG !== null) { file_put_contents(PROBE_LOG, $path."\n", FILE_APPEND); }
echo json_encode(['format' => ['start_time' => '0', 'duration' => (string) $duration], 'streams' => [
    ['index' => 0, 'codec_type' => 'video', 'avg_frame_rate' => '30/1'],
    ['index' => 1, 'codec_type' => 'audio', 'sample_rate' => '48000'],
], 'packets' => $packets]);
PHP;
        file_put_contents($ffprobeStub, str_replace(['ARGV_LOG', 'PROBE_LOG'], [var_export($argvLog, true), var_export($probeCallLog, true)], $probeCode));
        chmod($ffprobeStub, 0755);

        Config::set('media-processing.ffmpeg.ffmpeg_path', $ffmpegStub);
        Config::set('media-processing.ffmpeg.ffprobe_path', $ffprobeStub);

        return $argvLog;
    }

    #[Test]
    public function it_pairs_picture_and_sound_for_a_low_bitrate_source(): void
    {
        $argvLog = $this->stubFfmpegAndFfprobe(2_600_000); // 2.6 Mbps - a current-era upload

        $relativePath = $this->service->extractSegmentAsFile(
            '/tmp/input.mp4',
            (object) ['start_time' => 1.0, 'end_time' => 5.0]
        );

        $argv = file_get_contents($argvLog);
        $this->assertStringContainsString('trim=duration=', $argv);
        $this->assertStringContainsString('-c:a aac', $argv);
        $this->assertTrue(Storage::disk('local')->exists($relativePath));
    }

    #[Test]
    public function it_reencodes_a_high_bitrate_source(): void
    {
        Config::set('media-processing.video_extraction.reencode_crf', 23);
        $argvLog = $this->stubFfmpegAndFfprobe(21_800_000); // 21.8 Mbps - camera-original

        $relativePath = $this->service->extractSegmentAsFile(
            '/tmp/input.mp4',
            (object) ['start_time' => 1.0, 'end_time' => 5.0]
        );

        $argv = file_get_contents($argvLog);
        $this->assertStringContainsString('libx264', $argv);
        $this->assertStringContainsString('-crf 23', $argv);
        $this->assertStringNotContainsString('-frames:v', $argv);
        $this->assertTrue(Storage::disk('local')->exists($relativePath));
    }

    #[Test]
    public function a_legacy_zero_threshold_cannot_restore_stream_copy(): void
    {
        Config::set('media-processing.video_extraction.reencode_above_mbps', 0.0);
        $argvLog = $this->stubFfmpegAndFfprobe(48_900_000); // the heaviest source in the corpus

        $this->service->extractSegmentAsFile(
            '/tmp/input.mp4',
            (object) ['start_time' => 1.0, 'end_time' => 5.0]
        );

        $this->assertStringContainsString('libx264', file_get_contents($argvLog));
    }

    #[Test]
    public function a_source_whose_timing_cannot_be_read_is_rejected(): void
    {
        $argvLog = $this->stubFfmpegAndFfprobe(0);

        // This used to stream-copy, on the premise that a copy is never wrong,
        // only sometimes larger. The §4.1b censuses disproved that: a copy that
        // cannot see the keyframes starts its picture late. With nothing readable
        // the cut cannot be placed, so it is re-encoded, which is exact.
        $ffprobeStub = storage_path('framework/testing/ffprobe-stub.php');
        file_put_contents($ffprobeStub, "#!/bin/sh\nexit 1\n");
        chmod($ffprobeStub, 0755);

        $this->expectException(VideoProcessingException::class);
        $this->expectExceptionMessage('cannot be probed');
        $this->service->extractSegmentAsFile(
            '/tmp/input.mp4',
            (object) ['start_time' => 1.0, 'end_time' => 5.0]
        );

    }

    #[Test]
    public function a_reencoded_extract_is_written_to_the_configured_temp_disk_as_a_relative_path(): void
    {
        Storage::fake('historic_temp');
        Config::set('media-processing.storage.temp_disk', 'historic_temp');
        $this->service = new VideoExtractionService(app(AudioCompressionService::class), $this->storageHelper);
        $this->stubFfmpegAndFfprobe(21_800_000);

        $relativePath = $this->service->extractSegmentAsFile(
            '/tmp/input.mp4',
            (object) ['start_time' => 1.0, 'end_time' => 5.0]
        );

        // Regression: the re-encode branch wrote to storage_path('app/temp') and
        // returned an absolute path, ignoring the configured temp disk. Callers
        // store this value verbatim in processing_log.video_file_path, so an
        // absolute path is not merely untidy - it is a different contract.
        $this->assertStringStartsNotWith('/', $relativePath);
        $this->assertTrue(Storage::disk('historic_temp')->exists($relativePath));
        $this->assertFalse(Storage::disk('local')->exists($relativePath));
    }

    #[Test]
    public function it_reencodes_a_vp9_source_whose_bitrate_reads_as_unavailable(): void
    {

        // The historic webm corpus exactly: VP9, and a container reporting
        // neither duration nor bitrate. The bitrate rule alone reads that as
        // "nothing to do" and stream-copies VP9 into a .mp4, which muxes without
        // error and is then unplayable in Safari and every iOS browser.
        $argvLog = $this->stubFfmpegAndFfprobe(0, videoCodec: 'vp9');

        $relativePath = $this->service->extractSegmentAsFile(
            '/tmp/input.webm',
            (object) ['start_time' => 1.0, 'end_time' => 5.0]
        );

        $argv = file_get_contents($argvLog);
        $this->assertStringContainsString('libx264', $argv);
        $this->assertStringNotContainsString('-frames:v', $argv);
        $this->assertTrue(Storage::disk('local')->exists($relativePath));
    }

    #[Test]
    public function the_codec_rule_outranks_a_bitrate_comfortably_below_the_threshold(): void
    {
        $argvLog = $this->stubFfmpegAndFfprobe(1_000_000, videoCodec: 'vp9');

        $this->service->extractSegmentAsFile(
            '/tmp/input.webm',
            (object) ['start_time' => 1.0, 'end_time' => 5.0]
        );

        $this->assertStringContainsString('libx264', file_get_contents($argvLog));
    }

    #[Test]
    public function a_readable_stream_without_a_codec_label_still_uses_the_paired_encode(): void
    {
        $argvLog = $this->stubFfmpegAndFfprobe(2_600_000, videoCodec: '');

        $this->service->extractSegmentAsFile(
            '/tmp/input.mp4',
            (object) ['start_time' => 1.0, 'end_time' => 5.0]
        );

        $this->assertStringContainsString('libx264', file_get_contents($argvLog));
    }

    #[Test]
    public function all_codecs_use_an_accurate_input_seek(): void
    {

        $reencodeLog = $this->stubFfmpegAndFfprobe(0, videoCodec: 'vp9');
        $this->service->extractSegmentAsFile(
            '/tmp/input.webm',
            (object) ['start_time' => 900.0, 'end_time' => 1200.0]
        );
        $reencodeArgv = file_get_contents($reencodeLog);

        // Accurate seeking bounds decoding cost to the requested interval.
        $this->assertLessThan(
            strpos($reencodeArgv, '-i '),
            strpos($reencodeArgv, '-ss '),
            'A re-encode must seek the input: -ss has to precede -i.'
        );

        $copyLog = $this->stubFfmpegAndFfprobe(2_600_000);
        $this->service->extractSegmentAsFile(
            '/tmp/input.mp4',
            (object) ['start_time' => 900.0, 'end_time' => 1200.0]
        );
        $copyArgv = file_get_contents($copyLog);

        $this->assertStringContainsString('-ss 900 -t 300 -i ', $copyArgv);
        $this->assertStringContainsString('atrim=duration=300', $copyArgv);
    }

    #[Test]
    public function a_late_cut_seeks_directly_to_its_selected_start(): void
    {
        $argvLog = $this->stubFfmpegAndFfprobe(2_600_000);

        $this->service->extractSegmentAsFile(
            '/tmp/input.mp4',
            (object) ['start_time' => 2400.0, 'end_time' => 2700.0]
        );

        // Verified byte-identical against the single-output-seek form on three
        // corpus sources at 600 s, 2400 s and 3600 s: the pair of seeks sums to
        // the requested offset and the fine half still picks the keyframe.
        $argv = file_get_contents($argvLog);
        $this->assertStringContainsString('-ss 2400 -t 300 -i ', $argv);
        $this->assertStringContainsString('concat=n=1:v=1:a=1', $argv);
        $this->assertStringContainsString('-c:a aac', $argv);
    }

    #[Test]
    public function an_early_cut_uses_the_same_paired_input_path(): void
    {
        $argvLog = $this->stubFfmpegAndFfprobe(2_600_000);

        $this->service->extractSegmentAsFile(
            '/tmp/input.mp4',
            (object) ['start_time' => 12.0, 'end_time' => 40.0]
        );

        // There is no prefix worth skipping this close to the start, and a coarse
        // seek to a negative offset would be nonsense.
        $argv = file_get_contents($argvLog);
        $this->assertStringContainsString('-ss 12 -t 28 -i ', $argv);
    }

    #[Test]
    public function a_legacy_zero_pad_cannot_enable_an_output_seek(): void
    {
        Config::set('media-processing.video_extraction.copy_seek_prefix_seconds', 0.0);
        $argvLog = $this->stubFfmpegAndFfprobe(2_600_000);

        $this->service->extractSegmentAsFile(
            '/tmp/input.mp4',
            (object) ['start_time' => 900.0, 'end_time' => 1200.0]
        );

        $argv = file_get_contents($argvLog);
        $this->assertStringContainsString('-ss 900 -t 300 -i ', $argv);
    }

    #[Test]
    public function a_cut_has_one_paired_trim_and_concat_graph(): void
    {
        $argvLog = $this->stubFfmpegAndFfprobe(2_600_000);

        $this->service->extractSegmentAsFile(
            '/tmp/input.mp4',
            (object) ['start_time' => 900.0, 'end_time' => 1200.0]
        );

        $argv = file_get_contents($argvLog);
        $this->assertStringContainsString('trim=duration=300,setpts=PTS-STARTPTS', $argv);
        $this->assertStringContainsString('atrim=duration=300,asetpts=PTS-STARTPTS', $argv);
        $this->assertStringNotContainsString('-c copy', $argv);

    }

    #[Test]
    public function repeated_extractions_probe_each_source_once(): void
    {
        $probeLog = storage_path('framework/testing/ffprobe-calls.log');
        @unlink($probeLog);
        $this->stubFfmpegAndFfprobe(2_600_000, probeCallLog: $probeLog);

        $source = storage_path('framework/testing/probe-source.mp4');
        file_put_contents($source, 'fake-source');

        foreach ([100.0, 200.0, 300.0] as $start) {
            $this->service->extractSegmentAsFile(
                $source,
                (object) ['start_time' => $start, 'end_time' => $start + 60.0]
            );
        }

        $calls = collect(file($probeLog, FILE_IGNORE_NEW_LINES) ?: [])
            ->filter(fn (string $call): bool => $call === $source)->count();
        $this->assertSame(1, $calls, 'The full source timing scan is shared across selected clips.');

    }

    #[Test]
    public function aac_sound_is_encoded_in_the_same_pass_as_the_picture(): void
    {
        $argvLog = $this->stubFfmpegAndFfprobe(0, videoCodec: 'vp9', audioCodec: 'aac');

        $this->service->extractSegmentAsFile(
            '/tmp/input.webm',
            (object) ['start_time' => 1.0, 'end_time' => 5.0]
        );

        // Re-encoding AAC to AAC at the same bitrate spends a second generation
        // of lossy compression for no gain in size or compatibility.
        $argv = file_get_contents($argvLog);
        $this->assertStringNotContainsString('-c:a copy', $argv);
        $this->assertStringContainsString('-c:a aac', $argv);
    }

    #[Test]
    public function a_reencode_encodes_audio_the_container_cannot_carry(): void
    {
        $argvLog = $this->stubFfmpegAndFfprobe(0, videoCodec: 'vp9', audioCodec: 'opus');

        $this->service->extractSegmentAsFile(
            '/tmp/input.webm',
            (object) ['start_time' => 1.0, 'end_time' => 5.0]
        );

        // Opus is ordinary in a webm and does not mux into .mp4; copying it
        // through would fail the extraction outright.
        $argv = file_get_contents($argvLog);
        $this->assertStringContainsString('-c:a aac', $argv);
        $this->assertStringNotContainsString('-c:a copy', $argv);
    }
}
