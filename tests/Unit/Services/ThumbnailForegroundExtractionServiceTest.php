<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Media\Thumbnail\PixianClient;
use App\Services\Media\Thumbnail\ThumbnailForegroundExtractionService;
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
    public function it_returns_pixian_backed_cutout_metrics_on_success(): void
    {
        $mockClient = $this->createMock(PixianClient::class);
        $mockClient->expects($this->once())
            ->method('removeBackground')
            ->willReturn([
                'contents' => $this->makeForegroundPng(),
                'request_id' => 'req_test_123',
            ]);

        app()->instance(PixianClient::class, $mockClient);

        $result = $this->service->extract($this->makeSolidImage(400, 300, 44, 61, 118));

        $this->assertNotNull($result);
        $this->assertSame('pixian_api', $result['method']);
        $this->assertGreaterThan(0.02, $result['coverage']);
        $this->assertSame(['x' => 140, 'y' => 70, 'width' => 121, 'height' => 201], $result['bounds']);
    }

    #[Test]
    public function it_returns_null_when_pixian_does_not_return_a_cutout(): void
    {
        $mockClient = $this->createMock(PixianClient::class);
        $mockClient->expects($this->once())
            ->method('removeBackground')
            ->willReturn(null);

        app()->instance(PixianClient::class, $mockClient);

        $result = $this->service->extract($this->makeSolidImage(400, 300, 44, 61, 118));

        $this->assertNull($result);
    }

    #[Test]
    public function it_rejects_sparse_cutouts_that_leave_large_holes_inside_the_subject_bounds(): void
    {
        $mockClient = $this->createMock(PixianClient::class);
        $mockClient->expects($this->once())
            ->method('removeBackground')
            ->willReturn([
                'contents' => $this->makeSparseForegroundPng(),
                'request_id' => 'req_sparse_cutout',
            ]);

        app()->instance(PixianClient::class, $mockClient);

        $result = $this->service->extract($this->makeSolidImage(400, 300, 44, 61, 118));

        $this->assertNull($result);
    }

    private function makeSolidImage(int $width, int $height, int $red, int $green, int $blue): ImageInterface
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $color = imagecolorallocatealpha($image, $red, $green, $blue, 0);
        imagefill($image, 0, 0, $color);

        return Image::decode($image);
    }

    private function makeForegroundPng(
        int $width = 400,
        int $height = 300,
        int $left = 140,
        int $top = 70,
        int $right = 260,
        int $bottom = 270
    ): string {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);

        $subject = imagecolorallocatealpha($image, 20, 220, 40, 0);
        imagefilledrectangle($image, $left, $top, $right, $bottom, $subject);

        ob_start();
        imagepng($image);
        $contents = ob_get_clean();
        imagedestroy($image);

        if (! is_string($contents)) {
            $this->fail('Failed to create Pixian foreground image.');
        }

        return $contents;
    }

    private function makeSparseForegroundPng(): string
    {
        $image = imagecreatetruecolor(400, 300);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);

        $subject = imagecolorallocatealpha($image, 20, 220, 40, 0);
        imagefilledrectangle($image, 140, 70, 150, 270, $subject);
        imagefilledrectangle($image, 250, 70, 260, 270, $subject);

        ob_start();
        imagepng($image);
        $contents = ob_get_clean();
        imagedestroy($image);

        if (! is_string($contents)) {
            $this->fail('Failed to create sparse Pixian foreground image.');
        }

        return $contents;
    }
}
