<?php

declare(strict_types=1);

namespace App\Services\Media\Video;

use App\Exceptions\VideoProcessingException;
use App\Services\Media\Audio\AudioCompressionService;
use App\Services\Processing\StorageAdapterHelper;
use App\Traits\RequiresFfmpeg;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\Format\Audio\Mp3;
use FFMpeg\Media\Video;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoExtractionService
{
    use RequiresFfmpeg;

    private string $tempDisk;

    private string $permanentDisk;

    private string $audioPath;

    private SourceAwareMediaTimingChecker $timingChecker;

    public function __construct(
        private readonly AudioCompressionService $audioCompressor,
        private readonly StorageAdapterHelper $storageHelper
    ) {
        $this->timingChecker = app(SourceAwareMediaTimingChecker::class);
        $this->ffmpeg = $storageHelper->createFFMpeg();

        $this->tempDisk = config('media-processing.storage.temp_disk', 'local');
        $this->permanentDisk = config('media-processing.storage.sermon_disk', 'public');
        $this->audioPath = config('media-processing.storage.paths.audio', 'sermons/audio');
    }

    /** @param array<string, mixed> $options */
    public function extractSegment(string $inputPath, object $segment, array $options = []): string|UploadedFile
    {
        if (($options['return_type'] ?? 'file_path') === 'uploaded_file') {
            return $this->extractSegmentAsUpload($inputPath, $segment, $options['output_filename'] ?? null);
        }

        return $this->extractSegmentAsFile($inputPath, $segment, $options['output_filename'] ?? null);
    }

    /** @param  float|null  $audioFadeOut  Seconds over which the sound fades out at the end of the cut */
    public function extractSegmentAsFile(string $inputPath, object $segment, ?string $outputFilename = null, ?float $audioFadeOut = null): string
    {
        return $this->extractConcatenatedSegmentAsFile($inputPath, [[
            'start_time' => (float) ($segment->startTime ?? $segment->start_time ?? 0),
            'end_time' => (float) ($segment->endTime ?? $segment->end_time ?? 0),
        ]], $outputFilename, $audioFadeOut);
    }

    /**
     * Encode each span's picture and sound together, then concatenate paired streams.
     * Bump MediaProcessingVersion when changing cutting or enhancement behaviour.
     *
     * @param  list<array{start_time: float, end_time: float}>  $segments
     * @param  float|null  $audioFadeOut  Seconds over which the last span's sound fades out (a song ending at speech)
     */
    public function extractConcatenatedSegmentAsFile(string $inputPath, array $segments, ?string $outputFilename = null, ?float $audioFadeOut = null): string
    {
        $checker = $this->timingChecker;
        $checker->validateSpans($inputPath, $segments);
        $disk = Storage::disk($this->tempDisk);
        $relativePath = 'temp/'.($outputFilename ?? Str::uuid().'.mp4');
        $outputPath = $disk->path($relativePath);
        $disk->makeDirectory(dirname($relativePath));
        $filters = [];
        $inputs = [];
        $pairs = '';

        foreach ($segments as $index => $span) {
            // An accurate input seek bounds decoding cost to the selected interval.
            // Both streams then share the same requested origin, including fractional frames.
            $duration = $span['end_time'] - $span['start_time'];
            array_push($inputs, '-ss', $this->seconds($span['start_time']), '-t', $this->seconds($duration), '-i', escapeshellarg($inputPath));
            $filters[] = "[{$index}:v:0]trim=duration=".$this->seconds($duration).",setpts=PTS-STARTPTS[v{$index}]";
            $fade = '';

            if ($audioFadeOut !== null && $audioFadeOut > 0 && $index === array_key_last($segments)) {
                $length = min($audioFadeOut, $duration);
                $fade = ',afade=t=out:st='.$this->seconds($duration - $length).':d='.$this->seconds($length);
            }

            $filters[] = "[{$index}:a:0]atrim=duration=".$this->seconds($duration).",asetpts=PTS-STARTPTS{$fade}[a{$index}]";
            $pairs .= "[v{$index}][a{$index}]";
        }

        $filters[] = $pairs.'concat=n='.count($segments).':v=1:a=1[v][a]';

        try {
            $this->runFfmpeg([
                escapeshellarg((string) config('media-processing.ffmpeg.ffmpeg_path')),
                ...$inputs,
                '-filter_complex', escapeshellarg(implode(';', $filters)),
                '-map', '[v]', '-map', '[a]',
                ...$this->videoEncoderArguments(),
                '-pix_fmt', 'yuv420p', '-fps_mode', 'vfr',
                '-c:a', 'aac', '-ar', '48000', '-b:a', '128k',
                '-movflags', '+faststart', '-y', escapeshellarg($outputPath),
            ], 'paired section encode');
            $report = $checker->check($inputPath, $outputPath, $segments);
            Log::info('Section media passed source-aware timing checks', ['output_path' => $outputPath, 'timing_check' => $report]);

            return $relativePath;
        } catch (\Throwable $exception) {
            $disk->delete($relativePath);
            throw $exception;
        }
    }

    /**
     * Extract video segment and return as UploadedFile (for processing pipelines).
     *
     * @param  string  $inputPath  Absolute path to the source video
     * @param  object  $segment  Segment data with start_time and end_time
     * @param  string|null  $outputFilename  Optional custom filename for the output
     * @return UploadedFile The extracted segment as a Laravel UploadedFile
     *
     * @throws VideoProcessingException If extraction fails or times are invalid
     */
    public function extractSegmentAsUpload(string $inputPath, object $segment, ?string $outputFilename = null): UploadedFile
    {
        $startTime = $segment->startTime ?? $segment->start_time ?? 0;
        $endTime = $segment->endTime ?? $segment->end_time ?? 0;

        if ($startTime >= $endTime) {
            throw new VideoProcessingException('Invalid segment times: start time must be less than end time');
        }

        $duration = $endTime - $startTime;

        Log::info('Extracting video segment as UploadedFile', [
            'input_path' => $inputPath,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'duration' => $duration,
        ]);

        // Generate unique filename for extracted segment
        $segmentFilename = 'sermon_segment_'.Str::uuid().'.mp4';
        $segmentPath = 'temp/'.$segmentFilename;
        $fullSegmentPath = Storage::disk($this->tempDisk)->path($segmentPath);

        // Ensure temp directory exists
        $directory = dirname($fullSegmentPath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        try {
            $extractedPath = $this->extractSegmentAsFile($inputPath, $segment, $outputFilename);

            // Move to expected location if needed
            if ($extractedPath !== $fullSegmentPath) {
                rename(Storage::disk($this->tempDisk)->path($extractedPath), $fullSegmentPath);
            }

            Log::info('Video segment extracted successfully', [
                'original_video' => $inputPath,
                'extracted_segment' => $fullSegmentPath,
                'segment_size' => filesize($fullSegmentPath),
                'duration' => $duration,
            ]);

            // Create UploadedFile from the extracted segment
            $originalBasename = pathinfo($inputPath, PATHINFO_FILENAME);
            $uploadedFile = new UploadedFile(
                $fullSegmentPath,
                $originalBasename.'_sermon_segment.mp4',
                'video/mp4',
                null,
                true // Mark as test file to skip validation
            );

            return $uploadedFile;

        } catch (\Exception $e) {
            // Clean up partial file if extraction failed
            if (file_exists($fullSegmentPath)) {
                unlink($fullSegmentPath);
            }

            Log::error('Failed to extract video segment as UploadedFile', [
                'input_path' => $inputPath,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration' => $duration,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);

            throw new VideoProcessingException('Failed to extract video segment: '.$e->getMessage(), 0, $e);
        }
    }

    /** @return list<string> */
    private function videoEncoderArguments(): array
    {
        return [
            '-c:v', 'libx264',
            '-crf', (string) (int) config('media-processing.video_extraction.reencode_crf', 23),
            '-preset', (string) config('media-processing.video_extraction.reencode_preset', 'medium'),
        ];
    }

    /**
     * @param  list<string>  $arguments  Already shell-escaped where needed
     *
     * @throws VideoProcessingException
     */
    private function runFfmpeg(array $arguments, string $step): void
    {
        $output = [];
        exec(implode(' ', $arguments).' 2>&1', $output, $returnCode);

        if ($returnCode !== 0) {
            throw new VideoProcessingException("FFmpeg {$step} failed: ".implode("\n", $output));
        }
    }

    /**
     * Seconds as FFmpeg reads them: fixed-point, never PHP's exponent form.
     */
    private function seconds(float $seconds): string
    {
        return rtrim(rtrim(sprintf('%.6F', $seconds), '0'), '.');
    }

    /**
     * Extract audio from a video segment.
     *
     * @param  string  $inputVideoPath  Absolute path to the source video
     * @param  object  $segment  Segment data with start_time and end_time
     * @param  array<string, mixed>  $compressionOptions  Technical options (bitrate, channels)
     * @param  string|null  $outputFilename  Optional custom filename for the output
     * @return string The storage path to the extracted audio file
     *
     * @throws \Exception If extraction or S3 upload fails
     */
    public function extractAudio(
        string $inputVideoPath,
        object $segment,
        array $compressionOptions = [],
        ?string $outputFilename = null
    ): string {
        try {
            $startTime = $segment->startTime ?? $segment->start_time ?? 0;
            $endTime = $segment->endTime ?? $segment->end_time ?? 0;
            $duration = $endTime - $startTime;

            $outputFilename = $outputFilename ?: Str::uuid().'_sermon.mp3';

            // Get processing paths based on storage type
            $pathInfo = $this->getProcessingOutputPath($outputFilename);
            $processingPath = $pathInfo['processing_path'];
            $permanentPath = $pathInfo['permanent_path'];
            $useS3Processing = $pathInfo['use_temp_processing'];

            /** @var Video $video */
            $video = $this->requireFfmpeg()->open($inputVideoPath);

            $format = new Mp3;

            // Apply compression options if provided
            if (! empty($compressionOptions)) {
                $format->setAudioKiloBitrate($compressionOptions['bitrate'] ?? 128);
                if (isset($compressionOptions['channels'])) {
                    $format->setAudioChannels($compressionOptions['channels']);
                }
            } else {
                $format->setAudioKiloBitrate(128);
            }

            $startTimeCode = TimeCode::fromSeconds($startTime);
            $durationTimeCode = TimeCode::fromSeconds($duration);

            $video->clip($startTimeCode, $durationTimeCode)->save($format, $processingPath);

            // Handle S3 upload if needed
            if ($useS3Processing) {
                $this->uploadToPermanentStorage($processingPath, $permanentPath);
                // Clean up temporary file
                $this->cleanupTemporaryFile($processingPath);
                Log::info('Audio extracted and uploaded to S3', [
                    'input_path' => $inputVideoPath,
                    'permanent_path' => $permanentPath,
                    'start_time' => $startTime,
                    'duration' => $duration,
                    'compression_options' => $compressionOptions,
                ]);
            } else {
                Log::info('Audio extracted from video segment', [
                    'input_path' => $inputVideoPath,
                    'output_path' => $permanentPath,
                    'start_time' => $startTime,
                    'duration' => $duration,
                    'compression_options' => $compressionOptions,
                ]);
            }

            return $permanentPath;

        } catch (\Exception $e) {
            $segmentStart = null;
            if (property_exists($segment, 'startTime')) {
                $segmentStart = $segment->startTime;
            } elseif (property_exists($segment, 'start_time')) {
                $segmentStart = $segment->start_time;
            }

            Log::error('Failed to extract audio from video segment', [
                'error' => $e->getMessage(),
                'input_path' => $inputVideoPath,
                'segment_start' => $segmentStart,
                'compression_options' => $compressionOptions,
            ]);

            throw $e;
        }
    }

    /**
     * Extract optimized audio from segment with compression validation.
     * Delegates to AudioCompressionService; passes its own S3 upload handler.
     *
     * @param  string  $inputVideoPath  Absolute path to the source video
     * @param  object  $segment  Segment data with start_time and end_time
     * @param  string|null  $outputFilename  Optional custom filename for the output
     * @param  string|null  $permanentDisk  Optional disk name override
     * @param  string|null  $audioPath  Optional destination directory override
     * @return array{
     *     audio_path: string,
     *     full_path: string,
     *     original_size: int,
     *     final_size: int,
     *     compression_applied: bool,
     *     compression_ratio: float,
     *     valid_for_transcription: bool
     * }
     *
     * @throws \Exception If extraction fails
     */
    public function extractOptimizedAudio(
        string $inputVideoPath,
        object $segment,
        ?string $outputFilename = null,
        ?string $permanentDisk = null,
        ?string $audioPath = null,
    ): array {
        $resolvedPermanentDisk = $permanentDisk ?? $this->permanentDisk;

        return $this->audioCompressor->extractOptimizedAudio(
            $inputVideoPath,
            $segment,
            $outputFilename,
            $resolvedPermanentDisk,
            $audioPath,
            fn (string $localFilePath, string $permanentPath): string => $this->storageHelper->uploadWithRetry($localFilePath, $permanentPath, $resolvedPermanentDisk)
        );
    }

    /**
     * Upload a local file to permanent storage with exponential-backoff retry.
     */
    private function uploadToPermanentStorage(string $localFilePath, string $permanentPath): string
    {
        return $this->storageHelper->uploadWithRetry($localFilePath, $permanentPath, $this->permanentDisk);
    }

    private function cleanupTemporaryFile(string $filePath): void
    {
        $this->storageHelper->cleanupTempFile($filePath);
    }

    /**
     * Get the appropriate output path - temporary for S3 disks, direct for local disks.
     *
     * @return array{processing_path: string, permanent_path: string, use_temp_processing: bool}
     */
    private function getProcessingOutputPath(string $filename): array
    {
        return $this->storageHelper->getProcessingOutputPath(
            $filename,
            $this->audioPath,
            $this->permanentDisk,
            $this->tempDisk,
            'temp/audio_extraction'
        );
    }
}
