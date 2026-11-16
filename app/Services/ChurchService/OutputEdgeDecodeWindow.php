<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Exceptions\SegmentationException;
use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\RmsAnalysisService;
use App\Support\ServiceArtifactDisk;
use Illuminate\Support\Facades\Storage;

/** Pause/cap candidates measured in the 2026-10-27 wide-edge study. */
class OutputEdgeDecodeWindow
{
    public const RULE = 'cue-union-pause-0.8-cap-15-v3';

    private const PAUSE_SECONDS = 0.8;

    private const CAP_SECONDS = 15.0;

    /** @var array{samples: list<array{time: float, rms: float}>, threshold: float}|null */
    private ?array $cachedLevels = null;

    private ?string $cachedKey = null;

    /** @return array{samples: list<array{time: float, rms: float}>, threshold: float}|null */
    public function levels(MediaProcessingLog $log): ?array
    {
        $path = $log->rms_log_path;
        if (! is_string($path) || $path === '') {
            return null;
        }
        try {
            $content = Storage::disk(ServiceArtifactDisk::for($path))->get($path);
        } catch (\Throwable) {
            return null;
        }
        if (! is_string($content) || $content === '') {
            return null;
        }
        $key = hash('sha256', $content.json_encode(config('media-processing.segmentation'), JSON_THROW_ON_ERROR));
        if ($key === $this->cachedKey) {
            return $this->cachedLevels;
        }
        $rms = app(RmsAnalysisService::class);
        $samples = $rms->extractRmsData($content);
        if ($samples === []) {
            return null;
        }
        usort($samples, static fn (array $a, array $b): int => $a['time'] <=> $b['time']);
        try {
            $threshold = (float) $rms->determineThreshold($content)['threshold'];
        } catch (SegmentationException) {
            $threshold = $rms->getRmsThreshold();
        }
        $this->cachedKey = $key;
        $this->cachedLevels = ['samples' => $samples, 'threshold' => $threshold];

        return $this->cachedLevels;
    }

    /**
     * @param  list<array{time: float, rms: float}>  $samples
     * @return array{start: float, end: float, left_boundary: string, right_boundary: string}
     */
    public function bounds(array $samples, float $threshold, float $edge, ?float $duration): array
    {
        // Transcript filler can run past EOF. Clamp the decode centre, never the stored cut.
        $edge = max(0.0, min($duration ?? INF, $edge));
        $start = max(0.0, $edge - self::CAP_SECONDS);
        $end = min($duration ?? INF, $edge + self::CAP_SECONDS);
        $left = $start === 0.0 ? 'recording' : 'cap';
        $right = $end === $duration ? 'recording' : 'cap';
        $pauseStart = null;
        $previous = null;
        $finish = static function (?float $from, ?float $to) use (&$start, &$end, &$left, &$right, $edge): void {
            if ($from !== null && $to !== null && $to - $from >= self::PAUSE_SECONDS - 0.000001) {
                $midpoint = ($from + $to) / 2;
                if ($midpoint < $edge && $midpoint > $start) {
                    $start = $midpoint;
                    $left = 'pause';
                }
                if ($midpoint > $edge && $midpoint < $end) {
                    $end = $midpoint;
                    $right = 'pause';
                }
            }
        };
        foreach ($samples as $sample) {
            if ($duration !== null && $sample['time'] > $duration) {
                break;
            }
            if ($sample['time'] < $edge - 32.0) {
                continue;
            }
            if ($sample['time'] > $edge + 32.0) {
                break;
            }
            if ($previous !== null && $sample['time'] - $previous > 0.15) {
                $finish($pauseStart, $previous);
                $pauseStart = null;
            }
            if ($sample['rms'] < $threshold) {
                $pauseStart ??= $sample['time'];
            } else {
                $finish($pauseStart, $previous);
                $pauseStart = null;
            }
            $previous = $sample['time'];
        }
        $finish($pauseStart, $previous);

        return ['start' => round($start, 6), 'end' => round($end, 6), 'left_boundary' => $left, 'right_boundary' => $right];
    }
}
