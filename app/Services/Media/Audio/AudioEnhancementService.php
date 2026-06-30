<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Traits\SanitizesLogData;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class AudioEnhancementService
{
    use SanitizesLogData;

    /**
     * Enhance an audio file using FFmpeg filters (noise reduction, dynamic normalisation,
     * loudness normalisation) and write the result to a temp file.
     *
     * Returns the path to the enhanced file, or null if enhancement is disabled or fails.
     * Failure is always non-fatal — callers should fall back to the original file.
     *
     * @throws \Throwable
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
     * When loudness_norm is enabled, a two-pass approach is used:
     *   - Pass 1 measures the file's loudness stats via loudnorm's JSON output.
     *   - Pass 2 applies linear gain using those measured values.
     *
     * Returns null if no filters are enabled (nothing to do).
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
     * FFmpeg prints loudnorm JSON stats to stderr. Returns null if the pass fails
     * or the output cannot be parsed.
     *
     * @return array<string, float>|null
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
     * Extract and decode the loudnorm JSON block from FFmpeg stderr.
     *
     * @return array<string, float>|null
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

    /**
     * Run the FFmpeg enhancement pass with the built filter chain.
     *
     * @throws \RuntimeException When FFmpeg exits with a non-zero code
     */
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
     * The video stream is copied unchanged (`-c:v copy`); only the audio is re-encoded
     * to AAC to produce an MP4-compatible output. Reuses the same filter chain and
     * config toggles as enhance().
     *
     * Returns the path to the enhanced MP4 file, or null if enhancement is disabled or fails.
     * Failure is always non-fatal — callers should fall back to the original file.
     *
     * @throws \Throwable
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

    /**
     * Run the FFmpeg video enhancement pass: copy the video stream, re-encode audio to AAC.
     *
     * @throws \RuntimeException When FFmpeg exits with a non-zero code
     */
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
