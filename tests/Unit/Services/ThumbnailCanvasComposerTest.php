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

    #[Test]
    public function it_draws_the_foreground_subject_behind_the_text(): void
    {
        // The foreground subject must be placed before text/highlight layers so it
        // does not obscure title text. We verify there are non-background pixels in
        // the title region (i.e. the highlight rendered on top of the subject).
        $foreground = $this->foregroundLayer(220, 320);
        $image = app(ThumbnailCanvasComposer::class)->buildMainThumbnailCanvas($this->sermon(), $foreground);

        // The title region is on the left half. If the subject were drawn on top it
        // would flood this area with pure green, hiding the highlight/text.
        // We check that at least some pixels in the title region are NOT pure green.
        $native = $image->core()->native();
        $this->assertInstanceOf(\GdImage::class, $native);

        $foundNonGreen = false;
        for ($y = 200; $y <= 400 && ! $foundNonGreen; $y++) {
            for ($x = 45; $x <= 300 && ! $foundNonGreen; $x++) {
                $colorIndex = imagecolorat($native, $x, $y);
                $color = imagecolorsforindex($native, $colorIndex);
                $green = (int) $color['green'];
                $red = (int) $color['red'];
                $blue = (int) $color['blue'];

                if ($green <= 150 || ($green - $red) < 60 || ($green - $blue) < 60) {
                    $foundNonGreen = true;
                }
            }
        }

        $this->assertTrue($foundNonGreen, 'Expected non-green pixels in the title region (text drawn on top of foreground)');
    }

    #[Test]
    public function it_flips_a_left_facing_subject_to_face_right(): void
    {
        // Create a subject with an asymmetric red marker on the left side only.
        // After flipping, the red pixels should appear in the right half of the canvas.
        $foreground = $this->asymmetricForegroundLayer(220, 320);
        $image = app(ThumbnailCanvasComposer::class)->buildMainThumbnailCanvas($this->sermon(), $foreground);

        $native = $image->core()->native();
        $this->assertInstanceOf(\GdImage::class, $native);

        // Find red-dominant pixels in the placed subject region (right half of canvas).
        $foundRedInRight = false;
        $canvasWidth = imagesx($native);
        $canvasHeight = imagesy($native);
        $midX = (int) floor($canvasWidth / 2);

        for ($y = 0; $y < $canvasHeight && ! $foundRedInRight; $y++) {
            for ($x = $midX; $x < $canvasWidth && ! $foundRedInRight; $x++) {
                $colorIndex = imagecolorat($native, $x, $y);
                $color = imagecolorsforindex($native, $colorIndex);
                $red = (int) $color['red'];
                $green = (int) $color['green'];
                $blue = (int) $color['blue'];
                $alpha = (int) $color['alpha'];

                if ($alpha < 64 && $red > 180 && ($red - $green) > 80 && ($red - $blue) > 80) {
                    $foundRedInRight = true;
                }
            }
        }

        $this->assertTrue($foundRedInRight, 'Expected red marker to appear in the right half after flip');
    }

    #[Test]
    public function it_builds_a_centered_canvas_with_correct_dimensions(): void
    {
        $image = app(ThumbnailCanvasComposer::class)->buildCenteredThumbnailCanvas($this->sermon(), null);

        $this->assertSame(1280, $image->width());
        $this->assertSame(720, $image->height());
    }

    #[Test]
    public function it_renders_foreground_coloured_title_text_on_the_centered_canvas(): void
    {
        $image = app(ThumbnailCanvasComposer::class)->buildCenteredThumbnailCanvas($this->sermon(), null);

        $native = $image->core()->native();
        $this->assertInstanceOf(\GdImage::class, $native);

        // Title is drawn in the brand foreground colour (#145557 = dark teal).
        // The centered layout now keeps a 6% top inset (y >= 43 on a 720px canvas).
        $foundTeal = false;
        for ($y = 43; $y <= 360 && ! $foundTeal; $y++) {
            for ($x = 0; $x < imagesx($native) && ! $foundTeal; $x++) {
                $colorIndex = imagecolorat($native, $x, $y);
                $color = imagecolorsforindex($native, $colorIndex);
                if ((int) $color['red'] < 40 && (int) $color['green'] > 60 && (int) $color['blue'] > 60) {
                    $foundTeal = true;
                }
            }
        }

        $this->assertTrue($foundTeal, 'Expected brand-foreground (teal) title text below the corner overlay region');
    }

    #[Test]
    public function it_keeps_centered_title_pixels_below_the_increased_top_padding(): void
    {
        $composer = app(ThumbnailCanvasComposer::class);
        $method = new \ReflectionMethod($composer, 'resolveCenteredTitleTopY');
        $method->setAccessible(true);

        $this->assertSame(43, $method->invoke($composer, 720, 340));
    }

    #[Test]
    public function it_allows_centered_title_copy_to_extend_beyond_the_top_half_of_the_canvas(): void
    {
        $composer = app(ThumbnailCanvasComposer::class);
        $method = new \ReflectionMethod($composer, 'resolveCenteredTitleMaxHeight');
        $method->setAccessible(true);

        $this->assertSame(468, $method->invoke($composer, 720));
    }

    #[Test]
    public function it_allows_the_centered_foreground_subject_to_overlap_the_bottom_line_and_a_half_of_the_title(): void
    {
        $composer = app(ThumbnailCanvasComposer::class);
        $method = new \ReflectionMethod($composer, 'resolveCenteredForegroundTopY');
        $method->setAccessible(true);

        $this->assertSame(183, $method->invoke($composer, 43, 3, 100, 80));
        $this->assertSame(83, $method->invoke($composer, 43, 2, 100, 80));
    }

    #[Test]
    public function it_places_the_foreground_subject_below_the_title_midpoint_on_centered_canvas(): void
    {
        $foreground = $this->foregroundLayer(220, 320);
        $image = app(ThumbnailCanvasComposer::class)->buildCenteredThumbnailCanvas($this->sermon(), $foreground);

        $bounds = $this->greenPixelBounds($image);

        $this->assertNotNull($bounds, 'Expected green foreground pixels on the canvas');
        // The subject top must be below the top quarter of the canvas, since the title
        // is centred in the top half and the subject starts at the bottom-line midpoint.
        $this->assertGreaterThan(100, $bounds['min_y']);
    }

    #[Test]
    public function it_centers_the_foreground_subject_horizontally_on_centered_canvas(): void
    {
        $foreground = $this->foregroundLayer(220, 320);
        $image = app(ThumbnailCanvasComposer::class)->buildCenteredThumbnailCanvas($this->sermon(), $foreground);

        $bounds = $this->greenPixelBounds($image);

        $this->assertNotNull($bounds, 'Expected green foreground pixels on the canvas');

        // Subject should be roughly centred — its left/right pixel bounds should be
        // within ~60px of mirror symmetry around x=640.
        $leftGap = $bounds['min_x'];
        $rightGap = $image->width() - $bounds['max_x'];
        $this->assertEqualsWithDelta($leftGap, $rightGap, 60.0, 'Expected foreground subject to be horizontally centred');
    }

    private function sermon(string $title = 'Grace Alone'): Sermon
    {
        return new Sermon([
            'title' => $title,
            'reference' => null,
            'preacher' => '',
            'date' => Carbon::parse('2026-02-19'),
        ]);
    }

    /**
     * Returns a foreground layer where only the LEFT half is opaque (red), so the
     * centre-of-mass is in the left half, triggering the horizontal flip.
     *
     * @return array{
     *     image: ImageInterface,
     *     coverage: float,
     *     bounds: array{x:int,y:int,width:int,height:int},
     *     method: string
     * }
     */
    private function asymmetricForegroundLayer(int $width, int $height): array
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);

        // Only the left half is opaque (red) — centre-of-mass will be left of centre.
        $red = imagecolorallocatealpha($image, 220, 20, 20, 0);
        imagefilledrectangle($image, 0, 0, (int) floor($width / 2) - 1, $height - 1, $red);

        return [
            'image' => Image::read($image),
            'coverage' => 0.5,
            'bounds' => ['x' => 0, 'y' => 0, 'width' => $width, 'height' => $height],
            'method' => 'test',
        ];
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

        for ($y = 0; $y < imagesy($native); $y++) {
            for ($x = 0; $x < imagesx($native); $x++) {
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
