<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ThumbnailForegroundExtractionService;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Laravel\Facades\Image;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ThumbnailForegroundExtractionServiceTest extends TestCase
{
    private ThumbnailForegroundExtractionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ThumbnailForegroundExtractionService;
    }

    #[Test]
    public function it_extracts_a_foreground_cutout_from_a_blue_wall_frame(): void
    {
        $result = $this->service->extract($this->makeBlueWallImage(
            subjectX: 140,
            subjectY: 70,
            subjectWidth: 120,
            subjectHeight: 200
        ));

        $this->assertNotNull($result);
        $this->assertSame('blue_key', $result['method']);
        $this->assertGreaterThan(0.02, $result['coverage']);
        $this->assertGreaterThan(100, $result['bounds']['width']);
        $this->assertGreaterThan(150, $result['bounds']['height']);
    }

    #[Test]
    public function it_rejects_non_blue_backgrounds(): void
    {
        $result = $this->service->extract($this->makeSolidImage(400, 300, 120, 120, 120));

        $this->assertNull($result);
    }

    #[Test]
    public function it_rejects_masks_that_are_near_full_frame(): void
    {
        $result = $this->service->extract($this->makeBlueWallImage(
            subjectX: 20,
            subjectY: 20,
            subjectWidth: 360,
            subjectHeight: 250
        ));

        $this->assertNull($result);
    }

    #[Test]
    public function it_rejects_noisy_scattered_masks(): void
    {
        $image = $this->makeSolidImage(400, 300, 44, 61, 118);
        /** @var \GdImage $native */
        $native = $image->core()->native();
        $white = imagecolorallocatealpha($native, 255, 255, 255, 0);

        for ($i = 0; $i < 5000; $i++) {
            imagesetpixel($native, random_int(10, 389), random_int(10, 289), $white);
        }

        $result = $this->service->extract($image);

        $this->assertNull($result);
    }

    #[Test]
    public function it_preserves_dark_blue_subject_edges_that_are_darker_than_the_wall(): void
    {
        $image = $this->makeBlueWallImage(
            subjectX: 140,
            subjectY: 70,
            subjectWidth: 120,
            subjectHeight: 200
        );

        /** @var \GdImage $native */
        $native = $image->core()->native();
        $darkBlueSuit = imagecolorallocatealpha($native, 29, 35, 40, 0);

        imagefilledrectangle($native, 246, 150, 260, 270, $darkBlueSuit);

        $result = $this->service->extract($image);

        $this->assertNotNull($result);
        $this->assertLessThan(127, $this->alphaAt($result['image'], 252, 180));
    }

    #[Test]
    public function it_removes_bright_blue_wall_bloom_from_the_cutout(): void
    {
        $image = $this->makeBlueWallImage(
            subjectX: 140,
            subjectY: 70,
            subjectWidth: 120,
            subjectHeight: 200
        );

        /** @var \GdImage $native */
        $native = $image->core()->native();
        $bloom = imagecolorallocatealpha($native, 150, 170, 205, 0);

        imagefilledellipse($native, 345, 220, 100, 120, $bloom);

        $result = $this->service->extract($image);

        $this->assertNotNull($result);
        $this->assertSame(127, $this->alphaAt($result['image'], 345, 220));
    }

    #[Test]
    public function it_recovers_slightly_bright_blue_subject_edges_next_to_the_cutout(): void
    {
        $image = $this->makeBlueWallImage(
            subjectX: 140,
            subjectY: 70,
            subjectWidth: 120,
            subjectHeight: 200
        );

        /** @var \GdImage $native */
        $native = $image->core()->native();
        $brightBlueEdge = imagecolorallocatealpha($native, 47, 54, 68, 0);

        imagefilledrectangle($native, 246, 100, 260, 150, $brightBlueEdge);

        $result = $this->service->extract($image);

        $this->assertNotNull($result);
        $this->assertLessThan(127, $this->alphaAt($result['image'], 252, 120));
    }

    private function makeBlueWallImage(int $subjectX, int $subjectY, int $subjectWidth, int $subjectHeight): ImageInterface
    {
        $image = $this->makeSolidImage(400, 300, 44, 61, 118);
        /** @var \GdImage $native */
        $native = $image->core()->native();

        $suit = imagecolorallocatealpha($native, 25, 25, 25, 0);
        $shirt = imagecolorallocatealpha($native, 235, 235, 235, 0);
        $skin = imagecolorallocatealpha($native, 219, 171, 138, 0);

        imagefilledrectangle(
            $native,
            $subjectX,
            $subjectY + 40,
            $subjectX + $subjectWidth,
            $subjectY + $subjectHeight,
            $suit
        );

        imagefilledrectangle(
            $native,
            $subjectX + (int) floor($subjectWidth * 0.35),
            $subjectY + 55,
            $subjectX + (int) floor($subjectWidth * 0.65),
            $subjectY + $subjectHeight,
            $shirt
        );

        imagefilledellipse(
            $native,
            $subjectX + (int) floor($subjectWidth / 2),
            $subjectY + 35,
            (int) floor($subjectWidth * 0.42),
            70,
            $skin
        );

        return $image;
    }

    private function makeSolidImage(int $width, int $height, int $red, int $green, int $blue): ImageInterface
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $color = imagecolorallocatealpha($image, $red, $green, $blue, 0);
        imagefill($image, 0, 0, $color);

        return Image::read($image);
    }

    private function alphaAt(ImageInterface $image, int $x, int $y): int
    {
        /** @var \GdImage $native */
        $native = $image->core()->native();
        $color = imagecolorsforindex($native, imagecolorat($native, $x, $y));

        return (int) $color['alpha'];
    }
}
