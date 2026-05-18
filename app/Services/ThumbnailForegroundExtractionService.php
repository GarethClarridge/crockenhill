<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Laravel\Facades\Image;

class ThumbnailForegroundExtractionService
{
    private const string METHOD_PIXIAN_API = 'pixian_api';

    private const float MIN_FOREGROUND_DENSITY = 0.28;

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
        try {
            $sourcePath = $this->storeTemporarySourceImage($image);
            try {
                $result = app(PixianClient::class)->removeBackground($sourcePath);
            } finally {
                @unlink($sourcePath);
            }

            if ($result === null) {
                return null;
            }

            $foreground = Image::decode($result['contents']);
            $metrics = $this->measureForeground($foreground);

            if ($metrics['pixels'] === 0) {
                return null;
            }

            $bounds = $this->boundsFromMetrics($metrics);
            $density = $metrics['pixels'] / max(1, $bounds['width'] * $bounds['height']);

            if ($density < self::MIN_FOREGROUND_DENSITY) {
                Log::warning('Pixian foreground extraction rejected sparse cutout', [
                    'density' => round($density, 4),
                    'minimum_density' => self::MIN_FOREGROUND_DENSITY,
                    'bounds' => $bounds,
                ]);

                return null;
            }

            return [
                'image' => $foreground,
                'coverage' => $metrics['pixels'] / max(1, $foreground->width() * $foreground->height()),
                'bounds' => $bounds,
                'method' => self::METHOD_PIXIAN_API,
            ];
        } catch (\Throwable $e) {
            Log::warning('Pixian foreground extraction failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function storeTemporarySourceImage(ImageInterface $image): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pixian-thumb-');
        if ($path === false) {
            throw new \RuntimeException('Failed to create a temporary image path for Pixian.');
        }

        $image->encode(new PngEncoder)->save($path);

        return $path;
    }

    /**
     * @param  array{min_x:int,max_x:int,min_y:int,max_y:int,pixels:int}  $metrics
     * @return array{x:int,y:int,width:int,height:int}
     */
    private function boundsFromMetrics(array $metrics): array
    {
        return [
            'x' => $metrics['min_x'],
            'y' => $metrics['min_y'],
            'width' => ($metrics['max_x'] - $metrics['min_x']) + 1,
            'height' => ($metrics['max_y'] - $metrics['min_y']) + 1,
        ];
    }

    /**
     * @return array{min_x:int,max_x:int,min_y:int,max_y:int,pixels:int}
     */
    private function measureForeground(ImageInterface $image): array
    {
        $native = $image->core()->native();
        if (! $native instanceof \GdImage) {
            throw new \RuntimeException('Foreground extraction requires the GD image driver.');
        }

        $width = imagesx($native);
        $height = imagesy($native);
        $minX = $width - 1;
        $maxX = 0;
        $minY = $height - 1;
        $maxY = 0;
        $pixels = 0;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $colorIndex = imagecolorat($native, $x, $y);
                if ($colorIndex === false) {
                    continue;
                }

                $color = imagecolorsforindex($native, $colorIndex);
                if ((int) $color['alpha'] >= 127) {
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

        return [
            'min_x' => $minX,
            'max_x' => $maxX,
            'min_y' => $minY,
            'max_y' => $maxY,
            'pixels' => $pixels,
        ];
    }
}
