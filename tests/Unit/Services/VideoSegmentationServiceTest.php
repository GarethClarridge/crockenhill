<?php

namespace Tests\Unit\Services;

use App\Services\VideoSegmentationService;
use FFMpeg\FFProbe;
use FFMpeg\FFProbe\DataMapping\Format;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class VideoSegmentationServiceTest extends TestCase
{
    private VideoSegmentationService $service;

    private $mockFFProbe;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock FFProbe
        $this->mockFFProbe = Mockery::mock(FFProbe::class);

        // Create service instance and inject the mock
        $this->service = new VideoSegmentationService;

        // Use reflection to set the private ffprobe property
        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('ffprobe');
        $property->setAccessible(true);
        $property->setValue($this->service, $this->mockFFProbe);
    }

    public function test_validates_mp4_format(): void
    {
        $mockFormat = Mockery::mock(Format::class);
        $mockFormat->shouldReceive('get')
            ->with('format_name')
            ->andReturn('mov,mp4,m4a,3gp,3g2,mj2');

        $this->mockFFProbe->shouldReceive('format')
            ->with('/path/to/video.mp4')
            ->andReturn($mockFormat);

        Config::set('livestream-processing.supported_formats', ['mp4', 'mov', 'avi', 'mkv']);

        $result = $this->service->validateVideoFile('/path/to/video.mp4');

        $this->assertTrue($result);
    }

    public function test_validates_mkv_format_using_matroska_alias(): void
    {
        $mockFormat = Mockery::mock(Format::class);
        $mockFormat->shouldReceive('get')
            ->with('format_name')
            ->andReturn('matroska,webm');

        $this->mockFFProbe->shouldReceive('format')
            ->with('/path/to/video.mkv')
            ->andReturn($mockFormat);

        Config::set('livestream-processing.supported_formats', ['mp4', 'mov', 'avi', 'mkv']);
        Config::set('livestream-processing.format_aliases', [
            'mkv' => ['matroska'],
            'webm' => ['matroska', 'webm'],
        ]);

        $result = $this->service->validateVideoFile('/path/to/video.mkv');

        $this->assertTrue($result);
    }

    public function test_validates_mov_format(): void
    {
        $mockFormat = Mockery::mock(Format::class);
        $mockFormat->shouldReceive('get')
            ->with('format_name')
            ->andReturn('mov,mp4,m4a,3gp,3g2,mj2');

        $this->mockFFProbe->shouldReceive('format')
            ->with('/path/to/video.mov')
            ->andReturn($mockFormat);

        Config::set('livestream-processing.supported_formats', ['mp4', 'mov', 'avi', 'mkv']);

        $result = $this->service->validateVideoFile('/path/to/video.mov');

        $this->assertTrue($result);
    }

    public function test_rejects_unsupported_format(): void
    {
        $mockFormat = Mockery::mock(Format::class);
        $mockFormat->shouldReceive('get')
            ->with('format_name')
            ->andReturn('flv');

        $this->mockFFProbe->shouldReceive('format')
            ->with('/path/to/video.flv')
            ->andReturn($mockFormat);

        Config::set('livestream-processing.supported_formats', ['mp4', 'mov', 'avi', 'mkv']);
        Config::set('livestream-processing.format_aliases', [
            'mkv' => ['matroska'],
        ]);

        $result = $this->service->validateVideoFile('/path/to/video.flv');

        $this->assertFalse($result);
    }

    public function test_handles_ffprobe_exception(): void
    {
        $this->mockFFProbe->shouldReceive('format')
            ->with('/path/to/invalid.video')
            ->andThrow(new \Exception('FFprobe failed'));

        Config::set('livestream-processing.supported_formats', ['mp4', 'mov', 'avi', 'mkv']);

        $result = $this->service->validateVideoFile('/path/to/invalid.video');

        $this->assertFalse($result);
    }

    public function test_works_without_format_aliases_config(): void
    {
        $mockFormat = Mockery::mock(Format::class);
        $mockFormat->shouldReceive('get')
            ->with('format_name')
            ->andReturn('mp4');

        $this->mockFFProbe->shouldReceive('format')
            ->with('/path/to/video.mp4')
            ->andReturn($mockFormat);

        Config::set('livestream-processing.supported_formats', ['mp4', 'mov', 'avi', 'mkv']);
        // Don't set format_aliases config to test fallback

        $result = $this->service->validateVideoFile('/path/to/video.mp4');

        $this->assertTrue($result);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
