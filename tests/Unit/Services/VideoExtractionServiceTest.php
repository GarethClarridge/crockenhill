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
        // Stand in for ffmpeg: write fake content to the output path (the final
        // argument) and exit 0, mimicking a successful stream copy.
        $stubPath = storage_path('framework/testing/ffmpeg-stub.sh');
        file_put_contents($stubPath, "#!/bin/sh\nfor last in \"\$@\"; do :; done\nprintf 'fake-video' > \"\$last\"\n");
        chmod($stubPath, 0755);

        Config::set('media-processing.ffmpeg.ffmpeg_path', $stubPath);

        $relativePath = $this->service->extractSegmentAsFile(
            '/tmp/input.mp4',
            (object) ['start_time' => 1.0, 'end_time' => 5.0]
        );

        // Regression: the existence check used to pass the absolute path to
        // Storage::exists(), which expects a disk-relative path, so successful
        // extractions were reported as "Output file was not created".
        $this->assertTrue(Storage::disk('local')->exists($relativePath));
        $this->assertSame('fake-video', Storage::disk('local')->get($relativePath));

        unlink($stubPath);
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
     * `format=bit_rate`; the ffmpeg stub records the argv it was called with so a
     * test can assert which branch ran, then writes the output file and exits 0.
     */
    private function stubFfmpegAndFfprobe(int $probeBitrate): string
    {
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

        $ffprobeStub = storage_path('framework/testing/ffprobe-stub.sh');
        file_put_contents($ffprobeStub, "#!/bin/sh\necho {$probeBitrate}\n");
        chmod($ffprobeStub, 0755);

        Config::set('media-processing.ffmpeg.ffmpeg_path', $ffmpegStub);
        Config::set('media-processing.ffmpeg.ffprobe_path', $ffprobeStub);

        return $argvLog;
    }

    #[Test]
    public function it_stream_copies_a_source_at_or_below_the_reencode_threshold(): void
    {
        Config::set('media-processing.video_extraction.reencode_above_mbps', 6.0);
        $argvLog = $this->stubFfmpegAndFfprobe(2_600_000); // 2.6 Mbps - a current-era upload

        $relativePath = $this->service->extractSegmentAsFile(
            '/tmp/input.mp4',
            (object) ['start_time' => 1.0, 'end_time' => 5.0]
        );

        $argv = file_get_contents($argvLog);
        $this->assertStringContainsString('-c copy', $argv);
        $this->assertStringNotContainsString('libx264', $argv);
        $this->assertTrue(Storage::disk('local')->exists($relativePath));
    }

    #[Test]
    public function it_reencodes_a_source_above_the_reencode_threshold(): void
    {
        Config::set('media-processing.video_extraction.reencode_above_mbps', 6.0);
        Config::set('media-processing.video_extraction.reencode_crf', 23);
        $argvLog = $this->stubFfmpegAndFfprobe(21_800_000); // 21.8 Mbps - camera-original

        $relativePath = $this->service->extractSegmentAsFile(
            '/tmp/input.mp4',
            (object) ['start_time' => 1.0, 'end_time' => 5.0]
        );

        $argv = file_get_contents($argvLog);
        $this->assertStringContainsString('libx264', $argv);
        $this->assertStringContainsString('-crf 23', $argv);
        $this->assertStringNotContainsString('-c copy', $argv);
        $this->assertTrue(Storage::disk('local')->exists($relativePath));
    }

    #[Test]
    public function a_zero_threshold_always_stream_copies(): void
    {
        Config::set('media-processing.video_extraction.reencode_above_mbps', 0.0);
        $argvLog = $this->stubFfmpegAndFfprobe(48_900_000); // the heaviest source in the corpus

        $this->service->extractSegmentAsFile(
            '/tmp/input.mp4',
            (object) ['start_time' => 1.0, 'end_time' => 5.0]
        );

        $this->assertStringContainsString('-c copy', file_get_contents($argvLog));
    }

    #[Test]
    public function an_unreadable_source_bitrate_falls_back_to_stream_copy(): void
    {
        Config::set('media-processing.video_extraction.reencode_above_mbps', 6.0);
        $argvLog = $this->stubFfmpegAndFfprobe(0);

        // ffprobe reporting nothing usable must not be read as "below threshold"
        // by accident; a stream copy is never wrong, only sometimes larger.
        $ffprobeStub = storage_path('framework/testing/ffprobe-stub.sh');
        file_put_contents($ffprobeStub, "#!/bin/sh\nexit 1\n");
        chmod($ffprobeStub, 0755);

        $this->service->extractSegmentAsFile(
            '/tmp/input.mp4',
            (object) ['start_time' => 1.0, 'end_time' => 5.0]
        );

        $this->assertStringContainsString('-c copy', file_get_contents($argvLog));
    }

    #[Test]
    public function a_reencoded_extract_is_written_to_the_configured_temp_disk_as_a_relative_path(): void
    {
        Storage::fake('historic_temp');
        Config::set('media-processing.storage.temp_disk', 'historic_temp');
        Config::set('media-processing.video_extraction.reencode_above_mbps', 6.0);
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
}
