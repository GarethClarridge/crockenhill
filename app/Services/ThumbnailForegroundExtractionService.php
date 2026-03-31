<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Laravel\Facades\Image;

class ThumbnailForegroundExtractionService
{
    private const string METHOD_BLUE_KEY = 'blue_key';

    private const int SAMPLE_Y_PERCENT = 8;

    private const int MIN_BLUE_ADVANTAGE = 4;

    private const int MIN_GREEN_ADVANTAGE = 2;

    private const int MIN_EXTRACTION_BLUE_ADVANTAGE = 2;

    private const int MIN_EXTRACTION_GREEN_ADVANTAGE = 1;

    private const int MIN_CONFIDENT_BACKGROUND_BLUE_ADVANTAGE = 12;

    private const int MIN_CONFIDENT_BACKGROUND_GREEN_ADVANTAGE = 6;

    private const int MAX_SAMPLE_VARIANCE = 28;

    private const int HARD_BACKGROUND_DISTANCE = 32;

    private const int SOFT_BACKGROUND_DISTANCE = 62;

    private const int MAX_BACKGROUND_BRIGHTNESS_DIFFERENCE = 28;

    private const int MAX_DARK_FOREGROUND_PRESERVATION = 12;

    private const int MIN_BLOOM_BRIGHTNESS_INCREASE = 48;

    private const int MAX_EDGE_RECOVERY_BRIGHTNESS_DECREASE = 4;

    private const int MAX_EDGE_RECOVERY_BRIGHTNESS_INCREASE = 12;

    private const int EDGE_RECOVERY_ITERATIONS = 3;

    private const float MIN_COVERAGE = 0.02;

    private const float MAX_COVERAGE = 0.55;

    private const float MIN_WIDTH_RATIO = 0.12;

    private const float MAX_WIDTH_RATIO = 0.80;

    private const float MIN_HEIGHT_RATIO = 0.18;

    private const float MAX_HEIGHT_RATIO = 0.98;

    private const float MIN_FILL_RATIO = 0.12;

    private const float SUBJECT_CENTER_X_RATIO = 0.58;

    private const float SUBJECT_CENTER_Y_RATIO = 0.45;

    private const int HORIZONTAL_BOUND_PADDING = 24;

    private const int TOP_BOUND_PADDING = 72;

    private const int BOTTOM_BOUND_PADDING = 12;

    /**
     * @return array{
     *     image: ImageInterface,
     *     coverage: float,
     *     bounds: array{x:int,y:int,width:int,height:int},
     *     method: string
     * }|null
     */
    public function extract(ImageInterface $image): ?array
    {
        $nativeImage = $image->core()->native();

        if (! $nativeImage instanceof \GdImage) {
            Log::warning('Foreground extraction requires the GD image driver.');

            return null;
        }

        $width = imagesx($nativeImage);
        $height = imagesy($nativeImage);

        $backgroundColor = $this->detectBackgroundColor($nativeImage, $width, $height);
        if ($backgroundColor === null) {
            return null;
        }

        $extraction = $this->buildCutout($nativeImage, $backgroundColor, $width, $height);
        if (! $this->isValidExtraction($extraction, $width, $height)) {
            return null;
        }

        return [
            'image' => Image::read($extraction['image']),
            'coverage' => $extraction['coverage'],
            'bounds' => $extraction['bounds'],
            'method' => self::METHOD_BLUE_KEY,
        ];
    }

    /**
     * @return array{red:int,green:int,blue:int}|null
     */
    private function detectBackgroundColor(\GdImage $image, int $width, int $height): ?array
    {
        $sampleY = max(4, (int) floor($height * (self::SAMPLE_Y_PERCENT / 100)));
        $samplePositions = [0.10, 0.25, 0.50, 0.75, 0.90];
        $samples = [];

        foreach ($samplePositions as $samplePosition) {
            $sampleX = min($width - 1, max(0, (int) floor($width * $samplePosition)));
            $sample = $this->getPixelColor($image, $sampleX, $sampleY);

            if (! $this->isBlueDominant($sample)) {
                return null;
            }

            $samples[] = $sample;
        }

        $average = [
            'red' => (int) round(array_sum(array_column($samples, 'red')) / count($samples)),
            'green' => (int) round(array_sum(array_column($samples, 'green')) / count($samples)),
            'blue' => (int) round(array_sum(array_column($samples, 'blue')) / count($samples)),
        ];

        foreach ($samples as $sample) {
            $distance = $this->maxChannelDistance($sample, $average);

            if ($distance > self::MAX_SAMPLE_VARIANCE) {
                return null;
            }
        }

        return $average;
    }

    /**
     * @param  array{red:int,green:int,blue:int}  $backgroundColor
     * @return array{
     *     image: \GdImage,
     *     coverage: float,
     *     fill_ratio: float,
     *     bounds: array{x:int,y:int,width:int,height:int}
     * }
     */
    private function buildCutout(\GdImage $sourceImage, array $backgroundColor, int $width, int $height): array
    {
        $cutout = $this->createTransparentCanvas($width, $height);

        $colorCache = [];
        $visiblePixels = 0;
        $foregroundMask = array_fill(0, $width * $height, false);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixel = $this->getPixelColor($sourceImage, $x, $y);
                $alpha = $this->extractAlpha($pixel, $backgroundColor);

                if ($alpha >= 127) {
                    continue;
                }

                $cacheKey = implode(':', [$pixel['red'], $pixel['green'], $pixel['blue'], $alpha]);
                if (! array_key_exists($cacheKey, $colorCache)) {
                    $colorCache[$cacheKey] = $this->allocateColor(
                        $cutout,
                        $pixel['red'],
                        $pixel['green'],
                        $pixel['blue'],
                        $alpha
                    );
                }

                imagesetpixel($cutout, $x, $y, $colorCache[$cacheKey]);

                if ($alpha <= 100) {
                    $visiblePixels++;
                    $foregroundMask[($y * $width) + $x] = true;
                }
            }
        }

        $component = $this->extractSubjectComponent($foregroundMask, $width, $height);

        if ($visiblePixels === 0 || $component === null) {
            return [
                'image' => $cutout,
                'coverage' => 0.0,
                'fill_ratio' => 0.0,
                'bounds' => ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 0],
            ];
        }

        $recoveredMask = $this->recoverSubjectEdgePixels($component['mask'], $sourceImage, $backgroundColor, $width, $height);
        $maskMetrics = $this->measureMask($recoveredMask, $width, $height);

        $bounds = [
            'x' => max(0, $maskMetrics['min_x'] - self::HORIZONTAL_BOUND_PADDING),
            'y' => max(0, $maskMetrics['min_y'] - self::TOP_BOUND_PADDING),
            'width' => min($width - 1, $maskMetrics['max_x'] + self::HORIZONTAL_BOUND_PADDING) - max(0, $maskMetrics['min_x'] - self::HORIZONTAL_BOUND_PADDING) + 1,
            'height' => min($height - 1, $maskMetrics['max_y'] + self::BOTTOM_BOUND_PADDING) - max(0, $maskMetrics['min_y'] - self::TOP_BOUND_PADDING) + 1,
        ];

        $prunedCutout = $this->pruneCutoutToMask($cutout, $recoveredMask, $width, $height);

        $selectedVisiblePixels = $maskMetrics['pixels'];
        $coverage = $selectedVisiblePixels / ($width * $height);
        $fillRatio = $selectedVisiblePixels / max(1, ($bounds['width'] * $bounds['height']));

        return [
            'image' => $prunedCutout,
            'coverage' => $coverage,
            'fill_ratio' => $fillRatio,
            'bounds' => $bounds,
        ];
    }

    /**
     * @param  array{coverage:float,fill_ratio:float,bounds:array{x:int,y:int,width:int,height:int}}  $extraction
     */
    private function isValidExtraction(array $extraction, int $width, int $height): bool
    {
        $coverage = $extraction['coverage'];
        $fillRatio = $extraction['fill_ratio'];
        $bounds = $extraction['bounds'];

        if ($coverage < self::MIN_COVERAGE || $coverage > self::MAX_COVERAGE) {
            return false;
        }

        $widthRatio = $bounds['width'] / $width;
        $heightRatio = $bounds['height'] / $height;

        if ($widthRatio < self::MIN_WIDTH_RATIO || $widthRatio > self::MAX_WIDTH_RATIO) {
            return false;
        }

        if ($heightRatio < self::MIN_HEIGHT_RATIO || $heightRatio > self::MAX_HEIGHT_RATIO) {
            return false;
        }

        if ($fillRatio < self::MIN_FILL_RATIO) {
            return false;
        }

        return true;
    }

    /**
     * @param  array{red:int,green:int,blue:int,alpha:int}  $pixel
     * @param  array{red:int,green:int,blue:int}  $backgroundColor
     */
    private function extractAlpha(array $pixel, array $backgroundColor): int
    {
        $distance = $this->maxChannelDistance($pixel, $backgroundColor);
        $brightnessDifference = abs($this->brightness($pixel) - $this->brightness($backgroundColor));
        $pixelBrightness = $this->brightness($pixel);
        $backgroundBrightness = $this->brightness($backgroundColor);
        $backgroundLikeBlue = $this->isBlueDominant(
            $pixel,
            self::MIN_EXTRACTION_BLUE_ADVANTAGE,
            self::MIN_EXTRACTION_GREEN_ADVANTAGE,
        );
        $confidentBackgroundBlue = $this->isBlueDominant(
            $pixel,
            self::MIN_CONFIDENT_BACKGROUND_BLUE_ADVANTAGE,
            self::MIN_CONFIDENT_BACKGROUND_GREEN_ADVANTAGE,
        );

        if (
            $confidentBackgroundBlue
            && $distance <= self::HARD_BACKGROUND_DISTANCE
            && $brightnessDifference <= self::MAX_BACKGROUND_BRIGHTNESS_DIFFERENCE
            && $pixelBrightness >= ($backgroundBrightness - self::MAX_DARK_FOREGROUND_PRESERVATION)
        ) {
            return 127;
        }

        if (! $backgroundLikeBlue) {
            return 0;
        }

        if (
            ! $confidentBackgroundBlue
            && $pixelBrightness <= ($backgroundBrightness - self::MAX_DARK_FOREGROUND_PRESERVATION)
        ) {
            return 0;
        }

        if ($pixelBrightness >= ($backgroundBrightness + self::MIN_BLOOM_BRIGHTNESS_INCREASE)) {
            return 127;
        }

        if ($distance >= self::SOFT_BACKGROUND_DISTANCE || $brightnessDifference > (self::MAX_BACKGROUND_BRIGHTNESS_DIFFERENCE + 12)) {
            return 0;
        }

        $distanceRange = self::SOFT_BACKGROUND_DISTANCE - self::HARD_BACKGROUND_DISTANCE;
        $distanceFromHard = $distance - self::HARD_BACKGROUND_DISTANCE;

        return (int) round(127 * (1 - ($distanceFromHard / $distanceRange)));
    }

    /**
     * @param  array{red:int,green:int,blue:int,alpha?:int}  $color
     */
    private function isBlueDominant(
        array $color,
        int $minimumBlueAdvantage = self::MIN_BLUE_ADVANTAGE,
        int $minimumGreenAdvantage = self::MIN_GREEN_ADVANTAGE
    ): bool {
        return $color['blue'] >= ($color['red'] + $minimumBlueAdvantage)
            && $color['blue'] >= ($color['green'] + $minimumGreenAdvantage);
    }

    /**
     * @param  array<int, bool>  $foregroundMask
     * @return array{min_x:int,max_x:int,min_y:int,max_y:int,pixels:int,mask:array<int, bool>}|null
     */
    private function extractSubjectComponent(array $foregroundMask, int $width, int $height): ?array
    {
        $seedIndex = $this->findSubjectSeedIndex($foregroundMask, $width, $height);
        if ($seedIndex === null) {
            return null;
        }

        $visited = array_fill(0, $width * $height, false);
        $componentMask = array_fill(0, $width * $height, false);
        $queue = new \SplQueue;
        $queue->enqueue($seedIndex);
        $visited[$seedIndex] = true;

        $seedX = $seedIndex % $width;
        $seedY = intdiv($seedIndex, $width);
        $minX = $seedX;
        $maxX = $seedX;
        $minY = $seedY;
        $maxY = $seedY;
        $pixels = 0;

        while (! $queue->isEmpty()) {
            $index = $queue->dequeue();
            if (! $foregroundMask[$index]) {
                continue;
            }

            $componentMask[$index] = true;
            $pixels++;

            $x = $index % $width;
            $y = intdiv($index, $width);

            $minX = min($minX, $x);
            $maxX = max($maxX, $x);
            $minY = min($minY, $y);
            $maxY = max($maxY, $y);

            foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$deltaX, $deltaY]) {
                $nextX = $x + $deltaX;
                $nextY = $y + $deltaY;

                if ($nextX < 0 || $nextX >= $width || $nextY < 0 || $nextY >= $height) {
                    continue;
                }

                $nextIndex = ($nextY * $width) + $nextX;
                if ($visited[$nextIndex]) {
                    continue;
                }

                $visited[$nextIndex] = true;
                if (! $foregroundMask[$nextIndex]) {
                    continue;
                }

                $queue->enqueue($nextIndex);
            }
        }

        return [
            'min_x' => $minX,
            'max_x' => $maxX,
            'min_y' => $minY,
            'max_y' => $maxY,
            'pixels' => $pixels,
            'mask' => $componentMask,
        ];
    }

    /**
     * @param  array<int, bool>  $mask
     * @param  array{red:int,green:int,blue:int}  $backgroundColor
     * @return array<int, bool>
     */
    private function recoverSubjectEdgePixels(
        array $mask,
        \GdImage $sourceImage,
        array $backgroundColor,
        int $width,
        int $height
    ): array {
        $recoveredMask = $mask;

        for ($iteration = 0; $iteration < self::EDGE_RECOVERY_ITERATIONS; $iteration++) {
            $nextMask = $recoveredMask;

            for ($y = 1; $y < $height - 1; $y++) {
                for ($x = 1; $x < $width - 1; $x++) {
                    $index = ($y * $width) + $x;
                    if ($recoveredMask[$index]) {
                        continue;
                    }

                    if ($this->countSelectedNeighbors($recoveredMask, $width, $x, $y) < 2) {
                        continue;
                    }

                    $pixel = $this->getPixelColor($sourceImage, $x, $y);
                    if (! $this->shouldRecoverEdgePixel($pixel, $backgroundColor)) {
                        continue;
                    }

                    $nextMask[$index] = true;
                }
            }

            $recoveredMask = $nextMask;
        }

        return $recoveredMask;
    }

    /**
     * @param  array<int, bool>  $mask
     */
    private function countSelectedNeighbors(array $mask, int $width, int $x, int $y): int
    {
        $count = 0;

        foreach ([[-1, 0], [1, 0], [0, -1], [0, 1], [-1, -1], [1, -1], [-1, 1], [1, 1]] as [$deltaX, $deltaY]) {
            $neighborIndex = (($y + $deltaY) * $width) + ($x + $deltaX);
            if ($mask[$neighborIndex]) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array{red:int,green:int,blue:int,alpha:int}  $pixel
     * @param  array{red:int,green:int,blue:int}  $backgroundColor
     */
    private function shouldRecoverEdgePixel(array $pixel, array $backgroundColor): bool
    {
        if (! $this->isBlueDominant(
            $pixel,
            self::MIN_CONFIDENT_BACKGROUND_BLUE_ADVANTAGE,
            self::MIN_CONFIDENT_BACKGROUND_GREEN_ADVANTAGE,
        )) {
            return false;
        }

        $brightnessDelta = $this->brightness($pixel) - $this->brightness($backgroundColor);
        $distance = $this->maxChannelDistance($pixel, $backgroundColor);

        return $brightnessDelta >= -self::MAX_EDGE_RECOVERY_BRIGHTNESS_DECREASE
            && $brightnessDelta <= self::MAX_EDGE_RECOVERY_BRIGHTNESS_INCREASE
            && $distance <= self::HARD_BACKGROUND_DISTANCE;
    }

    /**
     * @param  array<int, bool>  $mask
     * @return array{min_x:int,max_x:int,min_y:int,max_y:int,pixels:int}
     */
    private function measureMask(array $mask, int $width, int $height): array
    {
        $minX = $width - 1;
        $maxX = 0;
        $minY = $height - 1;
        $maxY = 0;
        $pixels = 0;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if (! $mask[($y * $width) + $x]) {
                    continue;
                }

                $pixels++;
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minY = min($minY, $y);
                $maxY = max($maxY, $y);
            }
        }

        if ($pixels === 0) {
            return ['min_x' => 0, 'max_x' => 0, 'min_y' => 0, 'max_y' => 0, 'pixels' => 0];
        }

        return ['min_x' => $minX, 'max_x' => $maxX, 'min_y' => $minY, 'max_y' => $maxY, 'pixels' => $pixels];
    }

    /**
     * @param  array{red:int,green:int,blue:int,alpha?:int}  $left
     * @param  array{red:int,green:int,blue:int,alpha?:int}  $right
     */
    private function maxChannelDistance(array $left, array $right): int
    {
        return max(
            abs($left['red'] - $right['red']),
            abs($left['green'] - $right['green']),
            abs($left['blue'] - $right['blue']),
        );
    }

    /**
     * @param  array{red:int,green:int,blue:int,alpha?:int}  $color
     */
    private function brightness(array $color): int
    {
        return (int) round(($color['red'] + $color['green'] + $color['blue']) / 3);
    }

    /**
     * @return array{red:int,green:int,blue:int,alpha:int}
     */
    private function getPixelColor(\GdImage $image, int $x, int $y): array
    {
        $index = imagecolorat($image, $x, $y);
        if ($index === false) {
            throw new \RuntimeException('Failed to read source image pixel.');
        }

        $color = imagecolorsforindex($image, $index);

        return [
            'red' => (int) $color['red'],
            'green' => (int) $color['green'],
            'blue' => (int) $color['blue'],
            'alpha' => (int) $color['alpha'],
        ];
    }

    private function createTransparentCanvas(int $width, int $height): \GdImage
    {
        $canvas = imagecreatetruecolor(max(1, $width), max(1, $height));
        if (! $canvas instanceof \GdImage) {
            throw new \RuntimeException('Failed to create cutout canvas.');
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        $transparent = $this->allocateColor($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparent);

        return $canvas;
    }

    /**
     * @param  array<int, bool>  $mask
     */
    private function pruneCutoutToMask(\GdImage $cutout, array $mask, int $width, int $height): \GdImage
    {
        $pruned = $this->createTransparentCanvas($width, $height);
        $transparent = $this->allocateColor($pruned, 0, 0, 0, 127);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $index = ($y * $width) + $x;
                if (! $mask[$index]) {
                    continue;
                }

                $colorIndex = imagecolorat($cutout, $x, $y);
                if ($colorIndex === false) {
                    imagesetpixel($pruned, $x, $y, $transparent);

                    continue;
                }

                imagecolorsetpixel:
                imagesetpixel($pruned, $x, $y, $colorIndex);
            }
        }

        return $pruned;
    }

    /**
     * @param  array<int, bool>  $foregroundMask
     */
    private function findSubjectSeedIndex(array $foregroundMask, int $width, int $height): ?int
    {
        $targetX = (int) round($width * self::SUBJECT_CENTER_X_RATIO);
        $targetY = (int) round($height * self::SUBJECT_CENTER_Y_RATIO);
        $bestIndex = null;
        $bestDistance = null;

        $searchMinX = (int) round($width * 0.30);
        $searchMaxX = (int) round($width * 0.78);
        $searchMinY = (int) round($height * 0.12);
        $searchMaxY = (int) round($height * 0.85);

        for ($y = $searchMinY; $y <= $searchMaxY; $y++) {
            for ($x = $searchMinX; $x <= $searchMaxX; $x++) {
                $index = ($y * $width) + $x;
                if (! $foregroundMask[$index]) {
                    continue;
                }

                $distance = abs($x - $targetX) + abs($y - $targetY);
                if ($bestDistance === null || $distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestIndex = $index;
                }
            }
        }

        return $bestIndex;
    }

    private function allocateColor(\GdImage $image, int $red, int $green, int $blue, int $alpha): int
    {
        $allocated = imagecolorallocatealpha(
            $image,
            $this->clampColorChannel($red),
            $this->clampColorChannel($green),
            $this->clampColorChannel($blue),
            $this->clampAlphaChannel($alpha),
        );

        if ($allocated === false) {
            throw new \RuntimeException('Failed to allocate cutout pixel color.');
        }

        return $allocated;
    }

    /**
     * @return int<0, 255>
     */
    private function clampColorChannel(int $value): int
    {
        return max(0, min(255, $value));
    }

    /**
     * @return int<0, 127>
     */
    private function clampAlphaChannel(int $value): int
    {
        return max(0, min(127, $value));
    }
}
