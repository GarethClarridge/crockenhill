<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ProcessingResult;
use App\Services\ProcessingRouter;
use App\Services\SermonProcessingService;
use App\Services\VideoProcessingService;
use Illuminate\Http\Testing\File;
use Mockery;
use Tests\TestCase;

class ProcessingRouterTest extends TestCase
{
    private ProcessingRouter $router;

    private VideoProcessingService $mockVideoProcessor;

    private SermonProcessingService $mockSermonProcessor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockVideoProcessor = Mockery::mock(VideoProcessingService::class);
        $this->mockSermonProcessor = Mockery::mock(SermonProcessingService::class);

        $this->router = new ProcessingRouter(
            $this->mockVideoProcessor,
            $this->mockSermonProcessor
        );
    }

    public function test_it_routes_livestream_video_correctly(): void
    {
        $file = File::create('test-livestream.mp4', 1024);
        $expectedResult = ProcessingResult::success('test-id', 'Success');

        $this->mockVideoProcessor
            ->shouldReceive('processWithSegmentation')
            ->once()
            ->with($file)
            ->andReturn($expectedResult);

        $result = $this->router->routeLivestreamVideo($file);

        $this->assertSame($expectedResult, $result);
    }

    public function test_it_routes_sermon_video_correctly(): void
    {
        $file = File::create('test-sermon.mp4', 1024);
        $expectedResult = ProcessingResult::success('test-id', 'Success');

        $this->mockVideoProcessor
            ->shouldReceive('processDirectly')
            ->once()
            ->with($file)
            ->andReturn($expectedResult);

        $result = $this->router->routeSermonVideo($file);

        $this->assertSame($expectedResult, $result);
    }

    public function test_it_routes_audio_correctly(): void
    {
        $file = File::create('test-sermon.mp3', 1024);
        $expectedResult = ProcessingResult::success('test-id', 'Success');

        $this->mockSermonProcessor
            ->shouldReceive('processSermon')
            ->once()
            ->with($file)
            ->andReturn($expectedResult);

        $result = $this->router->routeAudio($file);

        $this->assertSame($expectedResult, $result);
    }

    public function test_it_returns_supported_types(): void
    {
        $types = $this->router->getSupportedTypes();

        $this->assertIsArray($types);
        $this->assertArrayHasKey('livestream', $types);
        $this->assertArrayHasKey('sermon_video', $types);
        $this->assertArrayHasKey('audio', $types);

        foreach ($types as $type => $config) {
            $this->assertArrayHasKey('description', $config);
            $this->assertArrayHasKey('allowed_extensions', $config);
            $this->assertArrayHasKey('max_size', $config);
        }
    }

    public function test_it_validates_file_for_livestream_type(): void
    {
        $validFile = File::create('test.mp4', 1024);
        $result = $this->router->validateFileForType($validFile, 'livestream');

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_it_rejects_invalid_file_extension(): void
    {
        $invalidFile = File::create('test.txt', 1024);
        $result = $this->router->validateFileForType($invalidFile, 'livestream');

        $this->assertFalse($result['valid']);
        $this->assertContains("File extension 'txt' not allowed for livestream. Allowed: mp4, mov, avi, mkv, webm", $result['errors']);
    }

    public function test_it_rejects_oversized_files(): void
    {
        // Create a large file (simulate by setting a very small max size in config)
        config(['livestream-processing.max_file_size' => 100]); // 100 bytes

        $oversizedFile = File::create('test.mp4', 1024); // 1KB file
        $result = $this->router->validateFileForType($oversizedFile, 'livestream');

        $this->assertFalse($result['valid']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('exceeds maximum limit', $result['errors'][0]);
    }

    public function test_it_rejects_unsupported_processing_type(): void
    {
        $file = File::create('test.mp4', 1024);
        $result = $this->router->validateFileForType($file, 'unsupported_type');

        $this->assertFalse($result['valid']);
        $this->assertContains('Unsupported processing type: unsupported_type', $result['errors']);
    }

    public function test_it_returns_routing_statistics(): void
    {
        $stats = $this->router->getRoutingStatistics();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('supported_types', $stats);
        $this->assertArrayHasKey('routes_available', $stats);
        $this->assertArrayHasKey('validation_rules', $stats);

        $this->assertEquals(['livestream', 'sermon_video', 'audio'], $stats['supported_types']);
        $this->assertCount(3, $stats['routes_available']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
