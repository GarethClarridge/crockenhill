<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Media\Video\FrameQualityScorer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FrameQualityScorerTest extends TestCase
{
    private FrameQualityScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scorer = new FrameQualityScorer;
    }

    #[Test]
    public function a_flat_black_frame_scores_zero(): void
    {
        $image = $this->solidImage(0, 0, 0);

        $this->assertSame(0.0, $this->scorer->score($image));
    }

    #[Test]
    public function a_flat_mid_grey_frame_scores_only_the_brightness_weight(): void
    {
        // Mid-grey is perfectly exposed (brightness score 1.0) but has zero
        // contrast and zero detail, so only the 0.25 brightness weight applies.
        $image = $this->solidImage(128, 128, 128);

        $this->assertSame(0.25, $this->scorer->score($image));
    }

    #[Test]
    public function a_flat_white_frame_scores_near_zero_for_poor_exposure(): void
    {
        // White is mid-tone-distant, so brightness ~0.0078 * 0.25 weight.
        $image = $this->solidImage(255, 255, 255);

        $this->assertSame(0.002, $this->scorer->score($image));
    }

    #[Test]
    public function a_high_contrast_detailed_frame_scores_higher_than_flat_grey(): void
    {
        $flatGrey = $this->solidImage(128, 128, 128);
        $stripes = $this->verticalStripesImage();

        $this->assertGreaterThan(
            $this->scorer->score($flatGrey),
            $this->scorer->score($stripes),
        );
    }

    #[Test]
    public function the_score_never_exceeds_one(): void
    {
        $stripes = $this->verticalStripesImage();

        $this->assertLessThanOrEqual(1.0, $this->scorer->score($stripes));
    }

    #[Test]
    public function a_single_pixel_frame_is_scored_without_error(): void
    {
        $image = $this->solidImage(0, 0, 0, width: 1, height: 1);

        $this->assertSame(0.0, $this->scorer->score($image));
    }

    private function solidImage(int $red, int $green, int $blue, int $width = 100, int $height = 100): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, $red, $green, $blue);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $color);

        return $image;
    }

    /**
     * Alternating black/white vertical stripes wider than the scorer's sample
     * step, so the sampling grid reliably captures the high contrast and detail.
     */
    private function verticalStripesImage(int $width = 100, int $height = 100, int $stripeWidth = 8): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        $black = imagecolorallocate($image, 0, 0, 0);
        $white = imagecolorallocate($image, 255, 255, 255);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $onWhiteStripe = intdiv($x, $stripeWidth) % 2 === 0;
                imagesetpixel($image, $x, $y, $onWhiteStripe ? $white : $black);
            }
        }

        return $image;
    }
}
