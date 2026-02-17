<?php

namespace Tests\Unit\Services;

use App\Data\LivestreamSegment;
use App\Services\AudioCompressionService;
use App\Services\VideoExtractionService;
use App\Services\VideoStorageService;
use Tests\TestCase;

class VideoStorageServiceCompressionTest extends TestCase
{
    protected VideoStorageService $service;

    protected VideoExtractionService $extractionService;

    protected AudioCompressionService $compressionService;

    protected string $testVideoPath;

    protected LivestreamSegment $testSegment;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock VideoExtractionService dependency
        $this->extractionService = $this->createMock(VideoExtractionService::class);
        $this->compressionService = $this->createMock(AudioCompressionService::class);
        $this->service = new VideoStorageService($this->extractionService, $this->compressionService);

        // Create a mock segment for testing
        $this->testSegment = new LivestreamSegment(
            startTime: 0.0,
            endTime: 60.0, // 1 minute segment
            duration: 60.0,
            classification: 'speech',
            avgRms: -20.0,
            peakRms: -10.0,
            isSermonCandidate: true,
            segmentOrder: 1
        );

        // Mock video path for testing
        $this->testVideoPath = storage_path('app/test_video.mp4');
    }

    public function test_video_storage_service_delegates_to_audio_compression_service(): void
    {
        $this->extractionService->expects($this->never())
            ->method('extractOptimizedAudio');

        // Mock the compression service to return expected result
        $this->compressionService->expects($this->once())
            ->method('extractOptimizedAudio')
            ->with(
                $this->testVideoPath,
                $this->testSegment,
                'test_sermon.mp3',
                $this->isType('callable')
            )
            ->willReturn([
                'audio_path' => 'sermons/audio/test_sermon.mp3',
                'full_path' => '/path/to/full/audio.mp3',
                'original_size' => 1024 * 1024,
                'final_size' => 1024 * 1024,
                'compression_applied' => false,
                'compression_ratio' => 1.0,
                'valid_for_transcription' => true,
            ]);

        $result = $this->service->extractOptimizedAudioFromSegment(
            $this->testVideoPath,
            $this->testSegment,
            'test_sermon.mp3'
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('audio_path', $result);
        $this->assertArrayHasKey('compression_applied', $result);
        $this->assertTrue($result['valid_for_transcription']);
    }

    public function test_container_resolves_video_storage_with_audio_compression_service(): void
    {
        $resolvedService = app(VideoStorageService::class);
        $reflection = new \ReflectionClass($resolvedService);
        $property = $reflection->getProperty('audioCompressor');
        $property->setAccessible(true);

        $this->assertInstanceOf(AudioCompressionService::class, $property->getValue($resolvedService));
    }

    public function test_video_segment_extraction_delegates_to_extraction_service(): void
    {
        // Mock the extraction service to return expected result
        $this->extractionService->expects($this->once())
            ->method('extractSegmentAsFile')
            ->with($this->testVideoPath, $this->testSegment, 'test_segment.mp4')
            ->willReturn('/path/to/extracted/segment.mp4');

        $result = $this->service->extractVideoSegment(
            $this->testVideoPath,
            $this->testSegment,
            'test_segment.mp4'
        );

        $this->assertIsString($result);
        $this->assertEquals('/path/to/extracted/segment.mp4', $result);
    }

    public function test_audio_extraction_delegates_to_extraction_service(): void
    {
        // Mock the extraction service to return expected result
        $this->extractionService->expects($this->once())
            ->method('extractAudio')
            ->with($this->testVideoPath, $this->testSegment, [], 'test_audio.mp3')
            ->willReturn('sermons/audio/test_audio.mp3');

        $result = $this->service->extractAudioFromSegment(
            $this->testVideoPath,
            $this->testSegment,
            'test_audio.mp3'
        );

        $this->assertIsString($result);
        $this->assertEquals('sermons/audio/test_audio.mp3', $result);
    }
}
