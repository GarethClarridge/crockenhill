<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Data\ThumbnailResult;
use App\Models\Sermon;
use App\Services\FrameExtractionService;
use App\Services\SermonExposurePolicy;
use App\Services\StorageAdapterHelper;
use App\Services\ThumbnailCanvasComposer;
use App\Services\ThumbnailForegroundExtractionService;
use App\Services\ThumbnailGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ThumbnailGenerationServiceCandidateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        Config::set('thumbnail-generation', [
            'enabled' => true,
            'storage' => [
                'disk' => 'public',
                'path' => 'sermons/thumbnails',
            ],
            'extraction' => [
                'start_offset' => 300,
                'end_buffer' => 60,
                'fallback_position' => 0.5,
                'min_video_duration' => 420,
            ],
            'processing' => [
                'temp_disk' => 'local',
                'temp_path' => 'temp/thumbnails',
                'cleanup_temp_files' => true,
            ],
            'ffmpeg' => [
                'path' => '/usr/bin/ffmpeg',
                'probe_path' => '/usr/bin/ffprobe',
                'timeout' => 300,
                'threads' => 2,
            ],
        ]);
    }

    public function test_it_selects_the_highest_scoring_thumbnail_candidate(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Scored Sermon',
            'date' => now(),
        ]);

        $frameExtractionService = $this->createConfiguredMock(FrameExtractionService::class, [
            'videoFileExists' => true,
            'ensureLocalVideoPath' => '/videos/test.mp4',
            'getVideoMetadata' => [
                'duration' => 1800.0,
                'width' => 1920,
                'height' => 1080,
                'format_name' => 'mp4',
                'size' => 1000000,
                'bit_rate' => 5000000,
                'codec' => 'h264',
            ],
            'calculateCandidateTimestamps' => [120.0, 240.0, 360.0],
        ]);

        $framePaths = [
            'temp/thumbnails/frame-1.webp',
            'temp/thumbnails/frame-2.webp',
            'temp/thumbnails/frame-3.webp',
        ];

        $this->writeFlatImage($framePaths[0], 128, 128, 128);
        $this->writeHorizontalGradient($framePaths[1]);
        $this->writeCheckerboard($framePaths[2]);

        $frameExtractionService->expects($this->exactly(3))
            ->method('extractBaseFrame')
            ->willReturnOnConsecutiveCalls(...$framePaths);

        $frameExtractionService->expects($this->once())
            ->method('cleanupDownloadedVideo')
            ->with(null);

        $service = new ThumbnailGenerationService(
            $frameExtractionService,
            app(StorageAdapterHelper::class),
            app(ThumbnailForegroundExtractionService::class),
            app(ThumbnailCanvasComposer::class),
            app(SermonExposurePolicy::class),
        );

        $result = $service->generateThumbnail($sermon, 'videos/test.mp4', 'public');

        $this->assertInstanceOf(ThumbnailResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertSame('candidate-3', $result->metadata['selected_thumbnail_candidate_id']);
        $this->assertSame('sermons/thumbnails/sermon_'.$sermon->id.'_'.date('Y-m-d').'_candidate-3_overlay.webp', $result->thumbnailPath);
        $this->assertCount(3, $result->metadata['thumbnail_candidates']);
        $this->assertSame('sermons/thumbnails/sermon_'.$sermon->id.'_'.date('Y-m-d').'_candidate-3_plain.webp', $result->metadata['plain_thumbnail_path']);
        $this->assertSame('sermons/thumbnails/sermon_'.$sermon->id.'_'.date('Y-m-d').'_candidate-3_card.webp', $result->metadata['card_thumbnail_path']);
        $candidatesById = collect($result->metadata['thumbnail_candidates'])->keyBy('id');
        $this->assertArrayHasKey('card_path', $candidatesById['candidate-3']);
        $this->assertArrayHasKey('overlay_path', $candidatesById['candidate-3']);
        $this->assertArrayNotHasKey('overlay_path', $candidatesById['candidate-1']);
        $this->assertArrayNotHasKey('overlay_path', $candidatesById['candidate-2']);
    }

    public function test_it_keeps_successful_candidates_when_one_plain_candidate_fails(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Partial Sermon',
            'date' => now(),
        ]);

        $frameExtractionService = $this->createConfiguredMock(FrameExtractionService::class, [
            'videoFileExists' => true,
            'ensureLocalVideoPath' => '/videos/test.mp4',
            'getVideoMetadata' => [
                'duration' => 1800.0,
                'width' => 1920,
                'height' => 1080,
                'format_name' => 'mp4',
                'size' => 1000000,
                'bit_rate' => 5000000,
                'codec' => 'h264',
            ],
            'calculateCandidateTimestamps' => [120.0, 240.0, 360.0],
        ]);

        $framePaths = [
            'temp/thumbnails/partial-frame-1.webp',
            'temp/thumbnails/partial-frame-2.webp',
            'temp/thumbnails/partial-frame-3.webp',
        ];

        $this->writeCheckerboard($framePaths[0]);
        $this->writeCheckerboard($framePaths[1]);
        $this->writeCheckerboard($framePaths[2]);

        $frameExtractionService->expects($this->exactly(3))
            ->method('extractBaseFrame')
            ->willReturnOnConsecutiveCalls(...$framePaths);

        $frameExtractionService->expects($this->once())
            ->method('cleanupDownloadedVideo')
            ->with(null);

        $service = $this->getMockBuilder(ThumbnailGenerationService::class)
            ->setConstructorArgs([
                $frameExtractionService,
                app(StorageAdapterHelper::class),
                app(ThumbnailForegroundExtractionService::class),
                app(ThumbnailCanvasComposer::class),
                app(SermonExposurePolicy::class),
            ])
            ->onlyMethods(['createPlainThumbnail'])
            ->getMock();

        $plainCall = 0;
        $service->method('createPlainThumbnail')
            ->willReturnCallback(function () use (&$plainCall): ?string {
                $plainCall++;

                if ($plainCall === 2) {
                    return null;
                }

                $path = "temp/thumbnails/plain-{$plainCall}.webp";
                $this->writeFlatImage($path, 180, 180, 180);

                return $path;
            });

        $result = $service->generateThumbnail($sermon, 'videos/test.mp4', 'public');

        $this->assertTrue($result->isSuccess());
        $this->assertCount(2, $result->metadata['thumbnail_candidates']);
        $this->assertSame(['candidate-1', 'candidate-3'], array_column($result->metadata['thumbnail_candidates'], 'id'));
        $candidatesById = collect($result->metadata['thumbnail_candidates'])->keyBy('id');
        $selectedCandidateId = $result->metadata['selected_thumbnail_candidate_id'];
        $this->assertContains($selectedCandidateId, ['candidate-1', 'candidate-3']);
        $this->assertArrayHasKey('card_path', $candidatesById[$selectedCandidateId]);
        $this->assertArrayHasKey('overlay_path', $candidatesById[$selectedCandidateId]);

        $nonSelectedCandidateId = $selectedCandidateId === 'candidate-1' ? 'candidate-3' : 'candidate-1';
        $this->assertArrayNotHasKey('overlay_path', $candidatesById[$nonSelectedCandidateId]);
    }

    private function writeFlatImage(string $path, int $red, int $green, int $blue): void
    {
        $image = imagecreatetruecolor(64, 36);
        $color = imagecolorallocate($image, $red, $green, $blue);
        imagefill($image, 0, 0, $color);

        $this->storeWebpImage($path, $image);
    }

    private function writeHorizontalGradient(string $path): void
    {
        $image = imagecreatetruecolor(64, 36);

        for ($x = 0; $x < 64; $x++) {
            $shade = (int) round(($x / 63) * 255);
            $color = imagecolorallocate($image, $shade, $shade, $shade);
            imageline($image, $x, 0, $x, 35, $color);
        }

        $this->storeWebpImage($path, $image);
    }

    private function writeCheckerboard(string $path): void
    {
        $image = imagecreatetruecolor(64, 36);
        $white = imagecolorallocate($image, 245, 245, 245);
        $black = imagecolorallocate($image, 15, 15, 15);

        for ($y = 0; $y < 36; $y += 6) {
            for ($x = 0; $x < 64; $x += 6) {
                imagefilledrectangle(
                    $image,
                    $x,
                    $y,
                    $x + 5,
                    $y + 5,
                    (($x + $y) / 6) % 2 === 0 ? $white : $black
                );
            }
        }

        $this->storeWebpImage($path, $image);
    }

    private function storeWebpImage(string $path, \GdImage $image): void
    {
        ob_start();
        imagewebp($image);
        $contents = ob_get_clean();
        imagedestroy($image);

        if (! is_string($contents)) {
            $this->fail('Failed to generate test image contents.');
        }

        Storage::disk('local')->put($path, $contents);
    }
}
