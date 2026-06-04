<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Scores how visually appealing a decoded video frame is, so the thumbnail
 * pipeline can rank candidate frames and pick the strongest one.
 *
 * The scoring is a pure function of pixel data: it samples the image on a grid,
 * derives per-pixel luminance, and combines three normalized sub-scores —
 * mid-tone brightness, contrast (luminance spread), and detail (local
 * luminance change). It performs no IO and knows nothing about storage or the
 * Sermon model, so it can be unit-tested directly against synthetic GdImages.
 *
 * Extracted from ThumbnailGenerationService::scoreFrameQuality, which now only
 * handles reading the frame off disk and decoding it before delegating here.
 */
class FrameQualityScorer
{
    /**
     * The grid is sampled at roughly this many steps across each dimension.
     */
    private const int SAMPLE_GRID_DIVISIONS = 48;

    /**
     * Relative weights for the three sub-scores. They sum to 1.0.
     */
    private const float BRIGHTNESS_WEIGHT = 0.25;

    private const float CONTRAST_WEIGHT = 0.35;

    private const float DETAIL_WEIGHT = 0.40;

    /**
     * Normalization divisors for the contrast and detail sub-scores. Higher
     * values make a given amount of contrast/detail score lower.
     */
    private const float CONTRAST_NORMALIZER = 64.0;

    private const float DETAIL_NORMALIZER = 80.0;

    /**
     * Score a decoded frame in the range [0.0, 1.0], rounded to 4 decimals.
     *
     * A score of 0.0 means a flat, poorly-exposed frame; higher scores indicate
     * well-exposed, high-contrast, detailed frames.
     */
    public function score(\GdImage $image): float
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $stepX = max(1, (int) floor($width / self::SAMPLE_GRID_DIVISIONS));
        $stepY = max(1, (int) floor($height / self::SAMPLE_GRID_DIVISIONS));

        $luminanceValues = [];
        $detailAccumulator = 0.0;

        for ($y = 0; $y < $height; $y += $stepY) {
            $previousRowLuminance = null;

            for ($x = 0; $x < $width; $x += $stepX) {
                $luminance = $this->luminanceAt($image, $x, $y);
                $luminanceValues[] = $luminance;

                if ($x >= $stepX) {
                    $previousLuminance = $this->luminanceAt($image, $x - $stepX, $y);
                    $detailAccumulator += abs($luminance - $previousLuminance);
                }

                if ($previousRowLuminance !== null) {
                    $detailAccumulator += abs($luminance - $previousRowLuminance);
                }

                $previousRowLuminance = $luminance;
            }
        }

        // The sampling loops always execute at least once for any valid GdImage
        // (dimensions are >= 1 and the step is clamped to >= 1), so there is
        // always at least one sample and no divide-by-zero is possible.
        $sampleCount = count($luminanceValues);

        $averageBrightness = array_sum($luminanceValues) / $sampleCount;
        $variance = array_sum(array_map(
            static fn (float $value): float => ($value - $averageBrightness) ** 2,
            $luminanceValues
        )) / $sampleCount;
        $contrast = sqrt($variance);
        $detail = $detailAccumulator / $sampleCount;

        $brightnessScore = max(0.0, 1 - (abs($averageBrightness - 128.0) / 128.0));
        $contrastScore = min(1.0, $contrast / self::CONTRAST_NORMALIZER);
        $detailScore = min(1.0, $detail / self::DETAIL_NORMALIZER);

        return round(
            ($brightnessScore * self::BRIGHTNESS_WEIGHT)
            + ($contrastScore * self::CONTRAST_WEIGHT)
            + ($detailScore * self::DETAIL_WEIGHT),
            4,
        );
    }

    /**
     * Compute the Rec. 709 relative luminance of a single pixel.
     */
    private function luminanceAt(\GdImage $image, int $x, int $y): float
    {
        $rgb = imagecolorat($image, $x, $y);
        $red = ($rgb >> 16) & 0xFF;
        $green = ($rgb >> 8) & 0xFF;
        $blue = $rgb & 0xFF;

        return (0.2126 * $red) + (0.7152 * $green) + (0.0722 * $blue);
    }
}
