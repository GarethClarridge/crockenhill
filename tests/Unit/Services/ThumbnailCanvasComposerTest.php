<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Sermon;
use App\Services\ThumbnailCanvasComposer;
use Carbon\Carbon;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Laravel\Facades\Image;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ThumbnailCanvasComposerTest extends TestCase
{
    #[Test]
    public function it_draws_the_logo_at_the_reduced_size(): void
    {
        $image = app(ThumbnailCanvasComposer::class)->buildMainThumbnailCanvas($this->sermon(), null);

        $bounds = $this->pixelBounds($image, 0, 0, 260, 260);

        $this->assertNotNull($bounds);
        $this->assertLessThanOrEqual(145, $bounds['max_x']);
        $this->assertLessThanOrEqual(155, $bounds['max_y']);
    }

    #[Test]
    public function it_vertically_centers_the_title_pixels_against_the_accent_line(): void
    {
        $image = app(ThumbnailCanvasComposer::class)->buildMainThumbnailCanvas($this->sermon(), null);

        $accentBounds = $this->pixelBounds($image, 45, 220, 70, 430);
        $titleBounds = $this->pixelBounds($image, 75, 220, 620, 430);

        $this->assertNotNull($accentBounds);
        $this->assertNotNull($titleBounds);

        $accentCenter = ($accentBounds['min_y'] + $accentBounds['max_y']) / 2;
        $titleCenter = ($titleBounds['min_y'] + $titleBounds['max_y']) / 2;

        $this->assertEqualsWithDelta($accentCenter, $titleCenter, 4.0);
    }

    #[Test]
    public function it_places_the_foreground_subject_with_the_same_top_and_right_inset_as_the_logo(): void
    {
        $foreground = $this->foregroundLayer(220, 320);
        $image = app(ThumbnailCanvasComposer::class)->buildMainThumbnailCanvas($this->sermon(), $foreground);

        $bounds = $this->greenPixelBounds($image);

        $this->assertNotNull($bounds);
        $this->assertSame(50, $bounds['min_y']);
        $this->assertSame(1229, $bounds['max_x']);
    }

    private function sermon(): Sermon
    {
        return new Sermon([
            'title' => 'Grace Alone',
            'reference' => null,
            'preacher' => '',
            'date' => Carbon::parse('2026-02-19'),
        ]);
    }

    /**
     * @return array{
     *     image: ImageInterface,
     *     coverage: float,
     *     bounds: array{x:int,y:int,width:int,height:int},
     *     method: string
     * }
     */
    private function foregroundLayer(int $width, int $height): array
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);

        $subject = imagecolorallocatealpha($image, 20, 220, 40, 0);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $subject);

        return [
            'image' => Image::read($image),
            'coverage' => 1.0,
            'bounds' => ['x' => 0, 'y' => 0, 'width' => $width, 'height' => $height],
            'method' => 'test',
        ];
    }

    /**
     * @return array{min_x:int,max_x:int,min_y:int,max_y:int}|null
     */
    private function pixelBounds(ImageInterface $image, int $minX, int $minY, int $maxX, int $maxY): ?array
    {
        $native = $image->core()->native();
        $this->assertInstanceOf(\GdImage::class, $native);

        $bounds = null;

        for ($y = $minY; $y <= min($maxY, imagesy($native) - 1); $y++) {
            for ($x = $minX; $x <= min($maxX, imagesx($native) - 1); $x++) {
                if (! $this->isNonBackgroundPixel($native, $x, $y)) {
                    continue;
                }

                $bounds ??= [
                    'min_x' => $x,
                    'max_x' => $x,
                    'min_y' => $y,
                    'max_y' => $y,
                ];

                $bounds['min_x'] = min($bounds['min_x'], $x);
                $bounds['max_x'] = max($bounds['max_x'], $x);
                $bounds['min_y'] = min($bounds['min_y'], $y);
                $bounds['max_y'] = max($bounds['max_y'], $y);
            }
        }

        return $bounds;
    }

    private function isNonBackgroundPixel(\GdImage $image, int $x, int $y): bool
    {
        $colorIndex = imagecolorat($image, $x, $y);
        $color = imagecolorsforindex($image, $colorIndex);

        if ((int) $color['alpha'] >= 127) {
            return false;
        }

        $distanceFromBackground = abs((int) $color['red'] - 215)
            + abs((int) $color['green'] - 234)
            + abs((int) $color['blue'] - 230);

        return $distanceFromBackground > 12;
    }

    /**
     * @return array{min_x:int,max_x:int,min_y:int,max_y:int}|null
     */
    private function greenPixelBounds(ImageInterface $image): ?array
    {
        $native = $image->core()->native();
        $this->assertInstanceOf(\GdImage::class, $native);

        $bounds = null;

        $startX = (int) floor(imagesx($native) / 2);

        for ($y = 0; $y < imagesy($native); $y++) {
            for ($x = $startX; $x < imagesx($native); $x++) {
                $colorIndex = imagecolorat($native, $x, $y);
                $color = imagecolorsforindex($native, $colorIndex);

                if ((int) $color['alpha'] >= 127) {
                    continue;
                }

                $red = (int) $color['red'];
                $green = (int) $color['green'];
                $blue = (int) $color['blue'];

                if ($green <= 150 || ($green - $red) < 60 || ($green - $blue) < 60) {
                    continue;
                }

                $bounds ??= [
                    'min_x' => $x,
                    'max_x' => $x,
                    'min_y' => $y,
                    'max_y' => $y,
                ];

                $bounds['min_x'] = min($bounds['min_x'], $x);
                $bounds['max_x'] = max($bounds['max_x'], $x);
                $bounds['min_y'] = min($bounds['min_y'], $y);
                $bounds['max_y'] = max($bounds['max_y'], $y);
            }
        }

        return $bounds;
    }
}
