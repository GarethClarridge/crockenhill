<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\SongLyricOcrService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongLyricOcrServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('openai.api_key', 'test-key');
        Config::set('media-processing.song_matching.ocr_model', 'gpt-4o-mini');
        Config::set('media-processing.storage.temp_disk', 'local');
        Config::set('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg');
    }

    #[Test]
    public function it_returns_lyrics_text_when_vision_model_reads_screen(): void
    {
        $lyricsText = "O Lord my God\nWhen I in awesome wonder";

        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    [
                        'message' => ['content' => $lyricsText],
                    ],
                ],
            ]),
        ]);

        $service = $this->makeServiceWithFakeFrame($lyricsText);

        $result = $service->extractLyrics(60.0, 180.0, '/fake/video.mp4');

        $this->assertSame($lyricsText, $result);
    }

    #[Test]
    public function it_returns_null_when_vision_model_returns_none(): void
    {
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    [
                        'message' => ['content' => 'NONE'],
                    ],
                ],
            ]),
        ]);

        $service = $this->makeServiceWithFakeFrame('NONE');

        $result = $service->extractLyrics(60.0, 180.0, '/fake/video.mp4');

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_when_vision_model_returns_empty_string(): void
    {
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    [
                        'message' => ['content' => '   '],
                    ],
                ],
            ]),
        ]);

        $service = $this->makeServiceWithFakeFrame('   ');

        $result = $service->extractLyrics(60.0, 180.0, '/fake/video.mp4');

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_when_frame_extraction_fails(): void
    {
        $service = new class extends SongLyricOcrService
        {
            /** @return array{0: string|null, 1: string|null} */
            protected function extractFrame(float $startTime, float $endTime, string $localVideoPath): array
            {
                return [null, null];
            }
        };

        $result = $service->extractLyrics(60.0, 180.0, '/fake/video.mp4');

        $this->assertNull($result);
    }

    #[Test]
    public function it_cleans_up_extracted_frame_after_successful_ocr(): void
    {
        Storage::fake('local');

        $framePath = 'temp/song-ocr/test_frame.jpg';
        Storage::disk('local')->put($framePath, 'fake-image-data');

        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    [
                        'message' => ['content' => 'Amazing grace how sweet the sound'],
                    ],
                ],
            ]),
        ]);

        $service = new class($framePath) extends SongLyricOcrService
        {
            public function __construct(private string $fakeFramePath) {}

            /** @return array{0: string|null, 1: string|null} */
            protected function extractFrame(float $startTime, float $endTime, string $localVideoPath): array
            {
                $fullPath = Storage::disk('local')->path($this->fakeFramePath);

                return [$this->fakeFramePath, $fullPath];
            }
        };

        $service->extractLyrics(60.0, 180.0, '/fake/video.mp4');

        Storage::disk('local')->assertMissing($framePath);
    }

    #[Test]
    public function it_cleans_up_frame_even_when_ocr_throws(): void
    {
        Storage::fake('local');

        $framePath = 'temp/song-ocr/error_frame.jpg';
        Storage::disk('local')->put($framePath, 'fake-image-data');

        $service = new class($framePath) extends SongLyricOcrService
        {
            public function __construct(private string $fakeFramePath) {}

            /** @return array{0: string|null, 1: string|null} */
            protected function extractFrame(float $startTime, float $endTime, string $localVideoPath): array
            {
                $fullPath = Storage::disk('local')->path($this->fakeFramePath);

                return [$this->fakeFramePath, $fullPath];
            }

            protected function callVisionApi(string $fullFramePath): string
            {
                throw new \RuntimeException('API error');
            }
        };

        $result = $service->extractLyrics(60.0, 180.0, '/fake/video.mp4');

        $this->assertNull($result);
        Storage::disk('local')->assertMissing($framePath);
    }

    /**
     * Creates a SongLyricOcrService subclass that skips real FFmpeg frame extraction
     * and instead returns a tiny synthetic JPEG so the vision API call can proceed.
     */
    private function makeServiceWithFakeFrame(string $ocrResponseText): SongLyricOcrService
    {
        return new class($ocrResponseText) extends SongLyricOcrService
        {
            private string $tempFile;

            public function __construct(private string $response)
            {
                $this->tempFile = tempnam(sys_get_temp_dir(), 'ocr_test_') ?: '/tmp/ocr_test';
                // Minimal valid JPEG header bytes so file_get_contents returns non-empty data.
                file_put_contents($this->tempFile, "\xFF\xD8\xFF\xE0fake-jpeg-data");
            }

            public function __destruct()
            {
                if (file_exists($this->tempFile)) {
                    unlink($this->tempFile);
                }
            }

            /** @return array{0: string|null, 1: string|null} */
            protected function extractFrame(float $startTime, float $endTime, string $localVideoPath): array
            {
                return ['temp/song-ocr/fake_frame.jpg', $this->tempFile];
            }

            protected function cleanupFrame(?string $framePath): void
            {
                // No-op — tempFile cleaned up in __destruct.
            }
        };
    }
}
