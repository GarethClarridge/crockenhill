<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Traits\SanitizesLogData;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Service for automated audio enhancement using FFmpeg.
 *
 * Provides a pipeline for noise reduction, dynamic range compression, and
 * two-pass loudness normalization (EBU R128) tailored for church recordings.
 * Failures in this service are designed to be non-fatal, allowing the media
 * processing pipeline to continue with original assets if enhancement fails.
 */
class AudioEnhancementService
{
    use SanitizesLogData;

    /**
     * Enhance an audio file using FFmpeg filters and write the result to a temp file.
     *
     * Coordinates the enhancement process: directory preparation, filter chain
     * construction, and FFmpeg execution. Failures are logged as warnings and
     * return null to signal that the caller should fall back to the original file.
     *
     * @param  string  $inputPath  Absolute path to the source audio file
     * @param  string  $processingId  Processing ID for log correlation and output naming
     * @return string|null Path to the enhanced MP3 file, or null if failed or disabled
     *
     * @throws \Throwable For unexpected system failures
     */
    public function enhance(string $inputPath, string $processingId): ?string
    {
        if (! config('media-processing.audio_enhancement.enabled', true)) {
            return null;
        }

        if (! file_exists($inputPath)) {
            Log::warning('AudioEnhancementService: input file not found', $this->sanitizeArrayForLog([
                'processing_id' => $processingId,
                'input_path' => $inputPath,
            ]));

            return null;
        }

        try {
            $outputPath = storage_path('app/temp/'.$processingId.'_enhanced.mp3');
            $this->ensureTempDirectoryExists($outputPath);

            $filterChain = $this->buildFilterChain($inputPath, $processingId);

            if ($filterChain === null) {
                return null;
            }

            $ffmpegPath = (string) config('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg');

            $this->runEnhancement($ffmpegPath, $inputPath, $filterChain, $outputPath, $processingId);

            Log::info('AudioEnhancementService: enhancement complete', $this->sanitizeArrayForLog([
                'processing_id' => $processingId,
                'output_path' => $outputPath,
            ]));

            return $outputPath;
        } catch (\Throwable $e) {
            Log::warning('AudioEnhancementService: enhancement failed, continuing with original', $this->sanitizeArrayForLog([
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
                'trace' => $this->sanitizeStackTrace($e->getTraceAsString()),
            ]));

            return null;
        }
    }

    /**
     * Build the FFmpeg -af filter chain string.
     *
     * Aggregates noise reduction, dynamic normalization, and loudness normalization
     * filters based on configuration. When loudness normalization is enabled, a
     * measurement pass (Pass 1) is executed first to determine the file's current
     * integrated loudness, allowing Pass 2 to apply precise gain.
     *
     * @param  string  $inputPath  Absolute path to the source file
     * @param  string  $processingId  Processing ID for logging
     * @return string|null The constructed filter chain, or null if no filters are active
     */
    public function buildFilterChain(string $inputPath, string $processingId): ?string
    {
        $filters = [];

        if (config('media-processing.audio_enhancement.noise_reduction', true)) {
            $filters[] = 'afftdn=nr=10:nf=-25';
        }

        if (config('media-processing.audio_enhancement.dynamic_norm', true)) {
            $filters[] = 'dynaudnorm';
        }

        $loudnessEnabled = config('media-processing.audio_enhancement.loudness_norm', true);

        if (! $loudnessEnabled) {
            return empty($filters) ? null : implode(',', $filters);
        }

        $targetLufs = (float) (config('media-processing.audio_enhancement.target_lufs') ?? -16.0);
        $truePeak = (float) (config('media-processing.audio_enhancement.true_peak') ?? -1.5);
        $lra = (float) (config('media-processing.audio_enhancement.lra') ?? 11.0);

        $measuredStats = $this->measureLoudness($inputPath, $processingId, $targetLufs, $truePeak, $lra);

        if ($measuredStats !== null && $this->isAlreadyWithinTolerance($measuredStats['input_i'], $targetLufs)) {
            Log::info('AudioEnhancementService: measured loudness within tolerance, skipping encode pass', $this->sanitizeArrayForLog([
                'processing_id' => $processingId,
                'measured_lufs' => $measuredStats['input_i'],
                'target_lufs' => $targetLufs,
                'tolerance_lufs' => config('media-processing.audio_enhancement.skip_tolerance_lufs', 2.0),
            ]));

            return empty($filters) ? null : implode(',', $filters);
        }

        if ($measuredStats !== null) {
            $filters[] = sprintf(
                'loudnorm=I=%.1f:TP=%.1f:LRA=%.1f:measured_I=%.2f:measured_TP=%.2f:measured_LRA=%.2f:measured_thresh=%.2f:offset=%.2f:linear=true',
                $targetLufs,
                $truePeak,
                $lra,
                $measuredStats['input_i'],
                $measuredStats['input_tp'],
                $measuredStats['input_lra'],
                $measuredStats['input_thresh'],
                $measuredStats['target_offset'],
            );
        } else {
            // Fall back to single-pass loudnorm if measurement failed
            $filters[] = sprintf('loudnorm=I=%.1f:TP=%.1f:LRA=%.1f', $targetLufs, $truePeak, $lra);
        }

        return implode(',', $filters);
    }

    /**
     * Returns true when the measured integrated loudness is already within the configured
     * tolerance of the target, meaning re-encoding would produce negligible audible change.
     */
    private function isAlreadyWithinTolerance(float $measuredLufs, float $targetLufs): bool
    {
        if (! config('media-processing.audio_enhancement.skip_if_within_tolerance', true)) {
            return false;
        }

        $tolerance = (float) config('media-processing.audio_enhancement.skip_tolerance_lufs', 2.0);

        return abs($measuredLufs - $targetLufs) <= $tolerance;
    }

    /**
     * Run FFmpeg first-pass loudnorm measurement and return the stats array.
     *
     * Executes FFmpeg with the `loudnorm` filter set to `print_format=json`.
     * The measurement result is captured from stderr and parsed into a
     * structured array.
     *
     * @param  string  $inputPath  Absolute path to the source file
     * @param  string  $processingId  Processing ID for logging
     * @param  float  $targetLufs  Target integrated loudness (LUFS)
     * @param  float  $truePeak  Target true peak (dBTP)
     * @param  float  $lra  Target loudness range (LU)
     * @return array{input_i: float, input_tp: float, input_lra: float, input_thresh: float, target_offset: float}|null
     */
    public function measureLoudness(string $inputPath, string $processingId, float $targetLufs, float $truePeak, float $lra): ?array
    {
        $ffmpegPath = (string) config('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg');

        $filter = sprintf('loudnorm=I=%.1f:TP=%.1f:LRA=%.1f:print_format=json', $targetLufs, $truePeak, $lra);

        $command = [$ffmpegPath, '-i', $inputPath, '-af', $filter, '-f', 'null', '/dev/null'];

        $process = new Process($command);
        $process->setTimeout(120);
        $process->run();

        // loudnorm JSON is written to stderr regardless of exit code
        $stderr = $process->getErrorOutput();

        return $this->parseLoudnormJson($stderr, $processingId);
    }

    /**
     * @return array{input_i: float, input_tp: float, input_lra: float, input_thresh: float, target_offset: float}|null
     */
    private function parseLoudnormJson(string $stderr, string $processingId): ?array
    {
        if (! preg_match('/\{[^}]+\}/s', $stderr, $matches)) {
            Log::warning('AudioEnhancementService: could not parse loudnorm JSON', $this->sanitizeArrayForLog([
                'processing_id' => $processingId,
            ]));

            return null;
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($matches[0], true);

        if (! is_array($data)) {
            return null;
        }

        $required = ['input_i', 'input_tp', 'input_lra', 'input_thresh', 'target_offset'];

        foreach ($required as $key) {
            if (! isset($data[$key])) {
                return null;
            }
        }

        return [
            'input_i' => (float) (is_numeric($data['input_i']) ? $data['input_i'] : 0),
            'input_tp' => (float) (is_numeric($data['input_tp']) ? $data['input_tp'] : 0),
            'input_lra' => (float) (is_numeric($data['input_lra']) ? $data['input_lra'] : 0),
            'input_thresh' => (float) (is_numeric($data['input_thresh']) ? $data['input_thresh'] : 0),
            'target_offset' => (float) (is_numeric($data['target_offset']) ? $data['target_offset'] : 0),
        ];
    }

    private function runEnhancement(string $ffmpegPath, string $inputPath, string $filterChain, string $outputPath, string $processingId): void
    {
        $command = [
            $ffmpegPath,
            '-y',
            '-i', $inputPath,
            '-af', $filterChain,
            '-c:a', 'libmp3lame',
            '-b:a', '128k',
            $outputPath,
        ];

        Log::info('AudioEnhancementService: running enhancement pass', $this->sanitizeArrayForLog([
            'processing_id' => $processingId,
            'filter_chain' => $filterChain,
        ]));

        $process = new Process($command);
        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(
                'FFmpeg enhancement failed: '.$process->getErrorOutput()
            );
        }
    }

    /**
     * Enhance the audio track of an MP4 video file using FFmpeg filters.
     *
     * The video stream is copied unchanged (`-c:v copy`) to avoid expensive and
     * quality-degrading re-encoding; only the audio stream is enhanced and
     * re-encoded to AAC for MP4 compatibility. Reuses the same filter chain and
     * configuration toggles as {@see enhance()}.
     *
     * @param  string  $inputPath  Absolute path to the source MP4 video file
     * @param  string  $processingId  Processing ID for log correlation and output naming
     * @return string|null Path to the enhanced MP4 file, or null if failed or disabled
     *
     * @throws \Throwable For unexpected system failures
     */
    public function enhanceVideo(string $inputPath, string $processingId): ?string
    {
        if (! config('media-processing.audio_enhancement.enabled', true)) {
            return null;
        }

        if (! file_exists($inputPath)) {
            Log::warning('AudioEnhancementService: video input file not found', $this->sanitizeArrayForLog([
                'processing_id' => $processingId,
                'input_path' => $inputPath,
            ]));

            return null;
        }

        try {
            $outputPath = storage_path('app/temp/'.$processingId.'_enhanced.mp4');
            $this->ensureTempDirectoryExists($outputPath);

            $filterChain = $this->buildFilterChain($inputPath, $processingId);

            if ($filterChain === null) {
                return null;
            }

            $ffmpegPath = (string) config('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg');

            $this->runVideoEnhancement($ffmpegPath, $inputPath, $filterChain, $outputPath, $processingId);

            Log::info('AudioEnhancementService: video enhancement complete', $this->sanitizeArrayForLog([
                'processing_id' => $processingId,
                'output_path' => $outputPath,
            ]));

            return $outputPath;
        } catch (\Throwable $e) {
            Log::warning('AudioEnhancementService: video enhancement failed, continuing with original', $this->sanitizeArrayForLog([
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
                'trace' => $this->sanitizeStackTrace($e->getTraceAsString()),
            ]));

            return null;
        }
    }

    private function runVideoEnhancement(string $ffmpegPath, string $inputPath, string $filterChain, string $outputPath, string $processingId): void
    {
        $command = [
            $ffmpegPath,
            '-y',
            '-i', $inputPath,
            '-af', $filterChain,
            '-c:v', 'copy',
            '-c:a', 'aac',
            '-b:a', '128k',
            $outputPath,
        ];

        Log::info('AudioEnhancementService: running video enhancement pass', $this->sanitizeArrayForLog([
            'processing_id' => $processingId,
            'filter_chain' => $filterChain,
        ]));

        $process = new Process($command);
        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(
                'FFmpeg video enhancement failed: '.$process->getErrorOutput()
            );
        }
    }

    private function ensureTempDirectoryExists(string $outputPath): void
    {
        $dir = dirname($outputPath);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
