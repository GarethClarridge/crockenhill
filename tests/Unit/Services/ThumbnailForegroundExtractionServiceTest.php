<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\PoofClient;
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
    public function it_returns_poof_backed_cutout_metrics_on_success(): void
    {
        $mockClient = $this->createMock(PoofClient::class);
        $mockClient->expects($this->once())
            ->method('removeBackground')
            ->willReturn([
                'contents' => $this->makeForegroundPng(),
                'request_id' => 'req_test_123',
            ]);

        app()->instance(PoofClient::class, $mockClient);

        $result = $this->service->extract($this->makeSolidImage(400, 300, 44, 61, 118));

        $this->assertNotNull($result);
        $this->assertSame('poof_api', $result['method']);
        $this->assertGreaterThan(0.02, $result['coverage']);
        $this->assertSame(['x' => 140, 'y' => 70, 'width' => 121, 'height' => 201], $result['bounds']);
    }

    #[Test]
    public function it_returns_null_when_poof_does_not_return_a_cutout(): void
    {
        $mockClient = $this->createMock(PoofClient::class);
        $mockClient->expects($this->once())
            ->method('removeBackground')
            ->willReturn(null);

        app()->instance(PoofClient::class, $mockClient);

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

        return Image::read($image);
    }

    private function makeForegroundPng(): string
    {
        $image = imagecreatetruecolor(400, 300);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);

        $subject = imagecolorallocatealpha($image, 20, 220, 40, 0);
        imagefilledrectangle($image, 140, 70, 260, 270, $subject);

        ob_start();
        imagepng($image);
        $contents = ob_get_clean();
        imagedestroy($image);

        if (! is_string($contents)) {
            $this->fail('Failed to create Poof foreground image.');
        }

        return $contents;
    }
}
