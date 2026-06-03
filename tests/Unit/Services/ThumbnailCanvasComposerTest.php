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
    // Wraps to three lines at a large font (163px), producing a tall title block
    // (≈Y 36..426) that both reaches the Y=43 top-padding clamp and extends past the
    // Y=360 midline. An over-long title shrinks to the 120px minimum and falls short
    // of the clamp, so the copy length is deliberately tuned to the centered layout.
    private const string TALL_CENTERED_TITLE = 'Blessed Are The Peacemakers';

    // Two long words that each fill a line, so the copy wraps to two lines under both
    // exact glyph metrics (imagettfbbox) AND the fallback estimator that
    // ThumbnailTextHelper uses when imagettfbbox is unavailable (strlen * fontSize *
    // 0.6 => 12 chars/line at the 150px centered font; each word here is <=12 chars).
    // A short title like "Grace Alone" (11 chars) stays on one line under the fallback.
    private const string TWO_LINE_CENTERED_TITLE = 'Boundless Compassion';

    // Centered title area spans Y=0..468 (65% of 720px). Overlay pixels below this must be excluded.
    private const int CENTERED_TITLE_MAX_Y = 468;

    /** @var array<string, ImageInterface> */
    private static array $canvasCache = [];

    public static function tearDownAfterClass(): void
    {
        self::$canvasCache = [];

        parent::tearDownAfterClass();
    }

    #[Test]
    public function it_draws_the_logo_at_the_reduced_size(): void
    {
        $image = $this->mainCanvas(null);

        $bounds = $this->pixelBounds($image, 0, 0, 260, 260);

        $this->assertNotNull($bounds);
        $this->assertLessThanOrEqual(145, $bounds['max_x']);
        $this->assertLessThanOrEqual(155, $bounds['max_y']);
    }

    #[Test]
    public function it_vertically_centers_the_title_pixels_against_the_accent_line(): void
    {
        $image = $this->mainCanvas(null);

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
        $image = $this->mainCanvas('symmetric');

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
        $image = $this->mainCanvas('symmetric');

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
        $image = $this->mainCanvas('asymmetric');

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
        $image = $this->centeredCanvas(null);

        $this->assertSame(1280, $image->width());
        $this->assertSame(720, $image->height());
    }

    #[Test]
    public function it_renders_foreground_coloured_title_text_on_the_centered_canvas(): void
    {
        $image = $this->centeredCanvas(null);

        $native = $image->core()->native();
        $this->assertInstanceOf(\GdImage::class, $native);

        // Title is drawn in the brand foreground colour (#145557 = dark teal).
        // The centered layout now keeps a 6% top inset (y >= 43 on a 720px canvas).
        $foundTeal = false;
        $width = imagesx($native);
        for ($y = 43; $y <= 360 && ! $foundTeal; $y++) {
            for ($x = 0; $x < $width && ! $foundTeal; $x++) {
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
        // Must use a tall title that actually reaches the Y=43 clamp; a short title
        // sits naturally below the clamp, making the assertion trivial, while an
        // over-long title shrinks to the 120px minimum and falls short of it.
        $image = $this->centeredCanvas(null, self::TALL_CENTERED_TITLE);

        // Restrict to the title region to exclude the bottom-corner overlay, which shares
        // the same foreground teal and would otherwise satisfy the Y bounds.
        $bounds = $this->tealPixelBounds($image, 0, self::CENTERED_TITLE_MAX_Y);

        $this->assertNotNull($bounds);

        // Title pixels should start at Y=43 (the 6% inset clamp), with a few px of
        // anti-aliasing spread above the glyph baseline box on either side.
        $this->assertGreaterThanOrEqual(33, $bounds['min_y']);
        $this->assertLessThanOrEqual(50, $bounds['min_y'], 'Expected title pixels to start near Y=43 (6% of 720px canvas), not significantly below it');
    }

    #[Test]
    public function it_allows_centered_title_copy_to_extend_beyond_the_top_half_of_the_canvas(): void
    {
        $image = $this->centeredCanvas(null, self::TALL_CENTERED_TITLE);

        // Restrict to the title region to exclude the bottom-corner overlay (same teal).
        // Canvas height 720, midpoint 360, max title height 468 (65%).
        $bounds = $this->tealPixelBounds($image, 0, self::CENTERED_TITLE_MAX_Y);

        $this->assertNotNull($bounds);

        $this->assertGreaterThan(360, $bounds['max_y'], 'Expected title pixels to extend into the bottom half of the canvas');
    }

    #[Test]
    public function it_allows_the_centered_foreground_subject_to_overlap_the_bottom_line_and_a_half_of_the_title(): void
    {
        // Measure title geometry on a subject-free render and the subject on the
        // composited render: the subject is drawn on top of the title, so measuring
        // the title where the opaque subject overlaps it would under-report its extent
        // (and line count) by exactly the region under test.
        //
        // TALL_CENTERED_TITLE is verified to wrap to three lines at a large font.
        $titleImage = $this->centeredCanvas(null, self::TALL_CENTERED_TITLE);
        $subjectImage = $this->centeredCanvas('symmetric', self::TALL_CENTERED_TITLE);

        // Guard the fixture: the overlap rule branches on line count, so confirm the
        // copy actually wrapped to three lines before asserting the three-line behaviour.
        $lineBands = $this->titleLineBands($titleImage, 0, self::CENTERED_TITLE_MAX_Y);
        $this->assertCount(3, $lineBands, 'Expected the three-line fixture to render on exactly three lines');

        $subjectBounds = $this->greenPixelBounds($subjectImage);
        $this->assertNotNull($subjectBounds);

        // The subject must start within the bottom line-and-a-half: a regression placing
        // it over all three lines (too high) or below the last line (no overlap) fails.
        $this->assertSubjectOverlapsBottomLineAndAHalf($lineBands, $subjectBounds['min_y'], 'three-line title');
    }

    #[Test]
    public function it_allows_the_centered_foreground_subject_to_overlap_a_two_line_title(): void
    {
        // Covers the two-line variant of the overlap rule (previously tested via reflection
        // as resolveCenteredForegroundTopY(43, 2, 100, 80) === 83).
        //
        // Measure title geometry on a subject-free render and the subject on the
        // composited render: the subject is drawn on top of the title, so measuring the
        // title where the opaque subject overlaps it would under-report its extent.
        $titleImage = $this->centeredCanvas(null, self::TWO_LINE_CENTERED_TITLE);
        $subjectImage = $this->centeredCanvas('symmetric', self::TWO_LINE_CENTERED_TITLE);

        // Guard the fixture itself: the overlap rule branches on line count, so confirm
        // the copy actually wrapped to two lines before asserting the two-line behaviour.
        // Without this, a font-metric change that rendered the title on one line would
        // silently exercise the wrong branch and still pass.
        $lineBands = $this->titleLineBands($titleImage, 0, self::CENTERED_TITLE_MAX_Y);
        $this->assertCount(2, $lineBands, 'Expected the two-line fixture to render on exactly two lines');

        $subjectBounds = $this->greenPixelBounds($subjectImage);
        $this->assertNotNull($subjectBounds);

        // For two lines the subject should start partway into the first line (the bottom
        // line-and-a-half), not above it (covering both lines) nor below the second line.
        $this->assertSubjectOverlapsBottomLineAndAHalf($lineBands, $subjectBounds['min_y'], 'two-line title');
    }

    #[Test]
    public function it_places_the_foreground_subject_below_the_title_midpoint_on_centered_canvas(): void
    {
        $image = $this->centeredCanvas('symmetric');

        $bounds = $this->greenPixelBounds($image);

        $this->assertNotNull($bounds, 'Expected green foreground pixels on the canvas');
        // The subject top must be below the top quarter of the canvas, since the title
        // is centred in the top half and the subject starts at the bottom-line midpoint.
        $this->assertGreaterThan(100, $bounds['min_y']);
    }

    #[Test]
    public function it_centers_the_foreground_subject_horizontally_on_centered_canvas(): void
    {
        $image = $this->centeredCanvas('symmetric');

        $bounds = $this->greenPixelBounds($image);

        $this->assertNotNull($bounds, 'Expected green foreground pixels on the canvas');

        // Subject should be roughly centred — its left/right pixel bounds should be
        // within ~60px of mirror symmetry around x=640.
        $leftGap = $bounds['min_x'];
        $rightGap = $image->width() - $bounds['max_x'];
        $this->assertEqualsWithDelta($leftGap, $rightGap, 60.0, 'Expected foreground subject to be horizontally centred');
    }

    /**
     * Build (and cache for the test class lifetime) a "main" thumbnail canvas.
     *
     * Several tests inspect different pixel regions of an otherwise identical
     * render — sharing the canvas keeps assertions independent while paying
     * the GD composition cost only once per fixture.
     *
     * @param  'symmetric'|'asymmetric'|null  $foreground
     */
    private function mainCanvas(?string $foreground): ImageInterface
    {
        return self::$canvasCache['main:'.($foreground ?? 'none')]
            ??= app(ThumbnailCanvasComposer::class)->buildMainThumbnailCanvas(
                $this->sermon(),
                $this->foregroundFor($foreground),
            );
    }

    /**
     * @param  'symmetric'|'asymmetric'|null  $foreground
     */
    private function centeredCanvas(?string $foreground, ?string $title = null): ImageInterface
    {
        $key = 'centered:'.($foreground ?? 'none').($title !== null ? ':'.md5($title) : '');

        return self::$canvasCache[$key]
            ??= app(ThumbnailCanvasComposer::class)->buildCenteredThumbnailCanvas(
                $this->sermon($title ?? 'Grace Alone'),
                $this->foregroundFor($foreground),
            );
    }

    /**
     * @param  'symmetric'|'asymmetric'|null  $kind
     * @return array{image: ImageInterface, coverage: float, bounds: array{x:int,y:int,width:int,height:int}, method: string}|null
     */
    private function foregroundFor(?string $kind): ?array
    {
        return match ($kind) {
            'symmetric' => $this->foregroundLayer(220, 320),
            'asymmetric' => $this->asymmetricForegroundLayer(220, 320),
            null => null,
        };
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
            'image' => Image::decode($image),
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
            'image' => Image::decode($image),
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
        $width = imagesx($native);
        $height = imagesy($native);

        for ($y = $minY; $y <= min($maxY, $height - 1); $y++) {
            for ($x = $minX; $x <= min($maxX, $width - 1); $x++) {
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
        $width = imagesx($native);
        $height = imagesy($native);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
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

    /**
     * @return array{min_x:int,max_x:int,min_y:int,max_y:int}|null
     */
    private function tealPixelBounds(ImageInterface $image, int $minY = 0, ?int $maxY = null): ?array
    {
        $native = $image->core()->native();
        $this->assertInstanceOf(\GdImage::class, $native);

        $bounds = null;
        $width = imagesx($native);
        $height = imagesy($native);
        $scanMaxY = min($maxY ?? $height, $height);

        for ($y = $minY; $y < $scanMaxY; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if (! $this->isTealPixel($native, $x, $y)) {
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

    private function countTitleLines(ImageInterface $image, int $minY, int $maxY): int
    {
        return count($this->titleLineBands($image, $minY, $maxY));
    }

    /**
     * Detect each rendered title line as a contiguous band of teal ink rows.
     *
     * Glyphs on a single line share a continuous baseline, so the only sizeable
     * vertical gaps in the teal-row profile fall between wrapped lines. A gap of a
     * few pixels is anti-aliasing noise; the inter-line gap is far larger.
     *
     * @return list<array{top:int,bottom:int}> ordered top-to-bottom
     */
    private function titleLineBands(ImageInterface $image, int $minY, int $maxY): array
    {
        $native = $image->core()->native();
        $this->assertInstanceOf(\GdImage::class, $native);

        $width = imagesx($native);
        $height = imagesy($native);
        $scanMaxY = min($maxY, $height);

        $bands = [];
        $gap = 0;
        $bandTop = null;
        $lastInkRow = 0;

        for ($y = $minY; $y < $scanMaxY; $y++) {
            $rowHasTeal = false;

            for ($x = 0; $x < $width; $x++) {
                if ($this->isTealPixel($native, $x, $y)) {
                    $rowHasTeal = true;
                    break;
                }
            }

            if ($rowHasTeal) {
                $bandTop ??= $y;
                $lastInkRow = $y;
                $gap = 0;

                continue;
            }

            // A run of >=8 empty rows ends the current line; smaller gaps are noise.
            if ($bandTop !== null && ++$gap >= 8) {
                $bands[] = ['top' => $bandTop, 'bottom' => $lastInkRow];
                $bandTop = null;
            }
        }

        if ($bandTop !== null) {
            $bands[] = ['top' => $bandTop, 'bottom' => $lastInkRow];
        }

        return $bands;
    }

    /**
     * Assert the foreground subject starts within the bottom line-and-a-half of the
     * title (CENTERED_SUBJECT_OVERLAP_LINES = 1.5). The subject top must fall strictly
     * below the second-from-last line's top — so it cannot cover the lines above the
     * bottom line-and-a-half — and at or above the final line's top, so it genuinely
     * overlaps. This frames the rule against the measured line bands rather than
     * pinning an exact coordinate, catching a subject placed too high (covering more
     * of the title) or too low (no meaningful overlap) without flaking on the few px
     * of anti-aliasing variation at the band edges.
     *
     * @param  list<array{top:int,bottom:int}>  $lineBands
     */
    private function assertSubjectOverlapsBottomLineAndAHalf(array $lineBands, int $subjectTop, string $context): void
    {
        $lineCount = count($lineBands);
        $this->assertGreaterThanOrEqual(2, $lineCount, "{$context}: expected at least two title lines");

        $secondLastLineTop = $lineBands[$lineCount - 2]['top'];
        $lastLineTop = $lineBands[$lineCount - 1]['top'];

        $this->assertGreaterThan(
            $secondLastLineTop,
            $subjectTop,
            "{$context}: subject starts too high (would cover more than the bottom line-and-a-half)"
        );
        $this->assertLessThanOrEqual(
            $lastLineTop + 8,
            $subjectTop,
            "{$context}: subject starts too low to overlap the bottom line-and-a-half"
        );
    }

    private function isTealPixel(\GdImage $image, int $x, int $y): bool
    {
        $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));

        if ((int) $color['alpha'] >= 127) {
            return false;
        }

        $red = (int) $color['red'];
        $green = (int) $color['green'];
        $blue = (int) $color['blue'];

        // Brand teal is #145557 (20, 85, 87). Detect by relative color dominance
        // to remain robust against anti-aliasing on the light background.
        return ($green - $red) >= 30 && ($blue - $red) >= 30 && abs($green - $blue) <= 30;
    }
}
