<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\ProcessingStrategyInterface;
use App\Services\ProcessingResult;
use App\Services\ProcessingRouter;
use App\Services\ProcessingStrategyRegistry;
use Illuminate\Http\Testing\File;
use Mockery;
use Tests\TestCase;

class ProcessingRouterTest extends TestCase
{
    private ProcessingRouter $router;

    private ProcessingStrategyRegistry $mockRegistry;

    private ProcessingStrategyInterface $mockAudioStrategy;

    private ProcessingStrategyInterface $mockVideoStrategy;

    private ProcessingStrategyInterface $mockLivestreamStrategy;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock strategies
        $this->mockAudioStrategy = Mockery::mock(ProcessingStrategyInterface::class);
        $this->mockVideoStrategy = Mockery::mock(ProcessingStrategyInterface::class);
        $this->mockLivestreamStrategy = Mockery::mock(ProcessingStrategyInterface::class);

        // Mock registry
        $this->mockRegistry = Mockery::mock(ProcessingStrategyRegistry::class);

        $this->router = new ProcessingRouter($this->mockRegistry);
    }

    public function test_it_routes_livestream_video_correctly(): void
    {
        $file = File::create('test-livestream.mp4', 1024);
        $expectedResult = ProcessingResult::success('test-id', 'Success');

        // Mock registry to return livestream strategy
        $this->mockRegistry
            ->shouldReceive('getStrategy')
            ->with('livestream')
            ->once()
            ->andReturn($this->mockLivestreamStrategy);

        // Mock strategy validation and processing
        $this->mockLivestreamStrategy
            ->shouldReceive('validateFile')
            ->with($file)
            ->once()
            ->andReturn(['valid' => true, 'errors' => []]);

        $this->mockLivestreamStrategy
            ->shouldReceive('process')
            ->with($file, [])
            ->once()
            ->andReturn($expectedResult);

        $result = $this->router->route('livestream', $file);

        $this->assertSame($expectedResult, $result);
    }

    public function test_it_routes_sermon_video_correctly(): void
    {
        $file = File::create('test-sermon.mp4', 1024);
        $expectedResult = ProcessingResult::success('test-id', 'Success');

        // Mock registry to return video strategy
        $this->mockRegistry
            ->shouldReceive('getStrategy')
            ->with('sermon_video')
            ->once()
            ->andReturn($this->mockVideoStrategy);

        // Mock strategy validation and processing
        $this->mockVideoStrategy
            ->shouldReceive('validateFile')
            ->with($file)
            ->once()
            ->andReturn(['valid' => true, 'errors' => []]);

        $this->mockVideoStrategy
            ->shouldReceive('process')
            ->with($file, [])
            ->once()
            ->andReturn($expectedResult);

        $result = $this->router->route('sermon_video', $file);

        $this->assertSame($expectedResult, $result);
    }

    public function test_it_routes_audio_correctly(): void
    {
        $file = File::create('test-sermon.mp3', 1024);
        $expectedResult = ProcessingResult::success('test-id', 'Success');

        // Mock registry to return audio strategy
        $this->mockRegistry
            ->shouldReceive('getStrategy')
            ->with('audio')
            ->once()
            ->andReturn($this->mockAudioStrategy);

        // Mock strategy validation and processing
        $this->mockAudioStrategy
            ->shouldReceive('validateFile')
            ->with($file)
            ->once()
            ->andReturn(['valid' => true, 'errors' => []]);

        $this->mockAudioStrategy
            ->shouldReceive('process')
            ->with($file, [])
            ->once()
            ->andReturn($expectedResult);

        $result = $this->router->route('audio', $file);

        $this->assertSame($expectedResult, $result);
    }

    public function test_it_returns_supported_types(): void
    {
        $expectedConfigs = [
            'audio' => ['type' => 'audio', 'description' => 'Audio processing'],
            'sermon_video' => ['type' => 'sermon_video', 'description' => 'Video processing'],
            'livestream' => ['type' => 'livestream', 'description' => 'Livestream processing'],
        ];

        $this->mockRegistry
            ->shouldReceive('getAllConfigurations')
            ->once()
            ->andReturn($expectedConfigs);

        $types = $this->router->getSupportedTypes();

        $this->assertIsArray($types);
        $this->assertEquals($expectedConfigs, $types);
    }

    public function test_it_validates_file_for_livestream_type(): void
    {
        $validFile = File::create('test.mp4', 1024);

        $this->mockRegistry
            ->shouldReceive('getStrategy')
            ->with('livestream')
            ->once()
            ->andReturn($this->mockLivestreamStrategy);

        $this->mockLivestreamStrategy
            ->shouldReceive('validateFile')
            ->with($validFile)
            ->once()
            ->andReturn(['valid' => true, 'errors' => []]);

        $result = $this->router->validateFileForType($validFile, 'livestream');

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_it_rejects_invalid_file_extension(): void
    {
        $invalidFile = File::create('test.txt', 1024);

        $this->mockRegistry
            ->shouldReceive('getStrategy')
            ->with('livestream')
            ->once()
            ->andReturn($this->mockLivestreamStrategy);

        $this->mockLivestreamStrategy
            ->shouldReceive('validateFile')
            ->with($invalidFile)
            ->once()
            ->andReturn([
                'valid' => false,
                'errors' => ["File extension 'txt' not allowed for livestream. Allowed: mp4, mov, avi, mkv, webm"]
            ]);

        $result = $this->router->validateFileForType($invalidFile, 'livestream');

        $this->assertFalse($result['valid']);
        $this->assertContains("File extension 'txt' not allowed for livestream. Allowed: mp4, mov, avi, mkv, webm", $result['errors']);
    }

    public function test_it_rejects_oversized_files(): void
    {
        $oversizedFile = File::create('test.mp4', 1024); // 1KB file

        $this->mockRegistry
            ->shouldReceive('getStrategy')
            ->with('livestream')
            ->once()
            ->andReturn($this->mockLivestreamStrategy);

        $this->mockLivestreamStrategy
            ->shouldReceive('validateFile')
            ->with($oversizedFile)
            ->once()
            ->andReturn([
                'valid' => false,
                'errors' => ['File size exceeds maximum limit']
            ]);

        $result = $this->router->validateFileForType($oversizedFile, 'livestream');

        $this->assertFalse($result['valid']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('exceeds maximum limit', $result['errors'][0]);
    }

    public function test_it_rejects_unsupported_processing_type(): void
    {
        $file = File::create('test.mp4', 1024);

        $this->mockRegistry
            ->shouldReceive('getStrategy')
            ->with('unsupported_type')
            ->once()
            ->andThrow(new \App\Exceptions\UnsupportedProcessingTypeException('unsupported_type'));

        $result = $this->router->validateFileForType($file, 'unsupported_type');

        $this->assertFalse($result['valid']);
        $this->assertContains('Unsupported processing type: unsupported_type', $result['errors']);
    }

    public function test_it_returns_routing_statistics(): void
    {
        $supportedTypes = ['audio', 'sermon_video', 'livestream'];
        $configurations = [
            'audio' => ['type' => 'audio'],
            'sermon_video' => ['type' => 'sermon_video'],
            'livestream' => ['type' => 'livestream'],
        ];

        $this->mockRegistry
            ->shouldReceive('getSupportedTypes')
            ->twice()
            ->andReturn($supportedTypes);

        $this->mockRegistry
            ->shouldReceive('getAllConfigurations')
            ->once()
            ->andReturn($configurations);

        $stats = $this->router->getRoutingStatistics();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('supported_types', $stats);
        $this->assertArrayHasKey('strategies_registered', $stats);
        $this->assertArrayHasKey('configurations', $stats);

        $this->assertEquals($supportedTypes, $stats['supported_types']);
        $this->assertEquals(count($supportedTypes), $stats['strategies_registered']);
        $this->assertEquals($configurations, $stats['configurations']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
