<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Exceptions\SegmentationException;
use App\Services\ChurchService\Structure\SustainedSoundSongSections;

/**
 * Where a recording holds sustained sound: loud, and without the pauses speech has.
 *
 * Measured over the historic corpus on 2026-09-15, per section against the run's own RMS
 * threshold: song sections are at least 84% active and break below the threshold at most 6.8
 * times a minute (10th and 90th percentiles); sermons, prayers, readings and notices are at most
 * 81% active and break at least 8.3 times a minute. A speaker pauses between phrases; a
 * congregation singing over instruments does not. Every one of 438 sermon sections reads as
 * speech by this measure.
 *
 * The recording is judged in 5 s bins, each on the 30 s around it by default, so one breath does
 * not split a song. Fresh audio adjudicated what it finds: {@see SustainedSoundSongSections}.
 */
final readonly class SustainedSound
{
    public const BIN_SECONDS = 5.0;

    /**
     * Thirty seconds, so one breath does not split a song.
     */
    public const WINDOW_BINS = 6;

    /**
     * Ten seconds, for placing an edge. The long window blurs a sung/spoken boundary by up to
     * 15 s either way, and invented a 37 s spoken lead-in on a clean song (1221 §2729) that the
     * short window placed within 7 s. Measured 2026-09-17 against fresh-audio onsets.
     */
    public const EDGE_WINDOW_BINS = 2;

    private const MINIMUM_ACTIVE_RATIO = 0.8;

    private const MAXIMUM_PAUSES_PER_MINUTE = 8.0;

    private const MINIMUM_PAUSE_SECONDS = 0.3;

    /**
     * @param  list<bool>  $bins  Whether each 5 s bin lies inside sustained sound
     * @param  float  $audioEnd  The last sample's time
     */
    private function __construct(
        public array $bins,
        public float $audioEnd,
    ) {}

    /**
     * Null when there are no samples to judge.
     *
     * @param  list<array{time: float, rms: float}>  $samples  Dataset from {@see RmsAnalysisService::extractRmsData()}
     * @param  float  $threshold  The run's silence threshold
     * @param  int  $windowBins  How many 5 s bins each bin is judged on
     */
    public static function fromSamples(array $samples, float $threshold, int $windowBins = self::WINDOW_BINS): ?self
    {
        if ($samples === []) {
            return null;
        }

        $audioEnd = $samples[count($samples) - 1]['time'];
        $binCount = (int) floor($audioEnd / self::BIN_SECONDS) + 1;
        $active = array_fill(0, $binCount, 0);
        $total = array_fill(0, $binCount, 0);
        $pauses = array_fill(0, $binCount, 0);
        $pauseStart = null;

        foreach ($samples as $sample) {
            $bin = min($binCount - 1, max(0, (int) floor($sample['time'] / self::BIN_SECONDS)));
            $total[$bin]++;

            if ($sample['rms'] <= $threshold) {
                $pauseStart ??= $sample['time'];

                continue;
            }

            $active[$bin]++;

            if ($pauseStart !== null && $sample['time'] - $pauseStart >= self::MINIMUM_PAUSE_SECONDS) {
                $pauses[$bin]++;
            }

            $pauseStart = null;
        }

        $half = intdiv($windowBins, 2);
        $bins = [];

        for ($bin = 0; $bin < $binCount; $bin++) {
            $from = max(0, $bin - $half);
            $length = min($binCount, $bin + $half) - $from;
            $windowTotal = array_sum(array_slice($total, $from, $length));
            $minutes = $length * self::BIN_SECONDS / 60.0;

            $bins[] = $windowTotal > 0
                && array_sum(array_slice($active, $from, $length)) / $windowTotal >= self::MINIMUM_ACTIVE_RATIO
                && array_sum(array_slice($pauses, $from, $length)) / $minutes <= self::MAXIMUM_PAUSES_PER_MINUTE;
        }

        return new self($bins, $audioEnd);
    }

    /**
     * Sustained sound from a raw RMS log, at the run's own adaptive threshold.
     */
    public static function fromRmsLog(string $rmsLogContent, RmsAnalysisService $rmsAnalysisService): ?self
    {
        $samples = $rmsAnalysisService->extractRmsData($rmsLogContent);

        if ($samples === []) {
            return null;
        }

        try {
            $threshold = (float) $rmsAnalysisService->determineThreshold($rmsLogContent)['threshold'];
        } catch (SegmentationException) {
            $threshold = $rmsAnalysisService->getRmsThreshold();
        }

        return self::fromSamples($samples, $threshold);
    }

    public function binCount(): int
    {
        return count($this->bins);
    }

    public function isSustainedBin(int $bin): bool
    {
        return $this->bins[$bin] ?? false;
    }

    /**
     * The share of the 5 s bins touching the interval that lie inside sustained sound.
     */
    public function share(float $from, float $to): float
    {
        $first = max(0, (int) floor($from / self::BIN_SECONDS));
        $last = min($this->binCount() - 1, (int) floor($to / self::BIN_SECONDS));

        if ($last < $first) {
            return 0.0;
        }

        $sustained = 0;

        for ($bin = $first; $bin <= $last; $bin++) {
            $sustained += $this->bins[$bin] ? 1 : 0;
        }

        return $sustained / ($last - $first + 1);
    }
}
