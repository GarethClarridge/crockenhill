<?php

namespace Tests\Unit;

use App\Services\MockTranscriptionService;
use App\Services\SermonProcessingLogger;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class MockTranscriptionServiceTest extends TestCase
{
    protected MockTranscriptionService $transcriptionService;

    protected $mockLogger;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure fresh storage for each test
        Storage::fake();

        $this->mockLogger = Mockery::mock(SermonProcessingLogger::class);
        $this->mockLogger->shouldReceive('logProcessingStep')->withAnyArgs()->byDefault();
        $this->transcriptionService = new MockTranscriptionService($this->mockLogger);
    }

    public function test_it_returns_sermon_7_content_for_transcription()
    {
        // Create the default transcript file for testing
        Storage::put('transcripts/sermon_7.md', 'This is mock transcript content from sermon 7.');

        $result = $this->transcriptionService->transcribe('fake/audio/path.mp3', 'test-processing-id');

        $this->assertIsString($result);
        $this->assertEquals('This is mock transcript content from sermon 7.', $result);
    }

    public function test_it_throws_exception_when_default_transcript_missing()
    {
        // Ensure the default transcript doesn't exist
        Storage::delete('transcripts/sermon_7.md');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Default transcript file not found');

        $this->transcriptionService->transcribe('fake/audio/path.mp3', 'test-processing-id');
    }

    public function test_it_can_store_and_retrieve_transcript()
    {
        $sermonId = 123;
        $transcript = 'This is a test transcript content.';

        // Store transcript
        $filePath = $this->transcriptionService->storeTranscript($sermonId, $transcript);

        $this->assertStringContainsString('sermon_123.md', $filePath);
        $this->assertTrue(Storage::exists($filePath));

        // Retrieve transcript
        $retrieved = $this->transcriptionService->getTranscript($sermonId);

        $this->assertEquals($transcript, $retrieved);
    }

    public function test_it_can_delete_transcript()
    {
        $sermonId = 456;
        $transcript = 'Another test transcript.';

        // Store transcript first
        $this->transcriptionService->storeTranscript($sermonId, $transcript);
        $this->assertTrue($this->transcriptionService->transcriptExists($sermonId));

        // Delete transcript
        $success = $this->transcriptionService->deleteTranscript($sermonId);

        $this->assertTrue($success);
        $this->assertFalse($this->transcriptionService->transcriptExists($sermonId));
    }

    public function test_it_returns_null_when_transcript_does_not_exist()
    {
        $sermonId = 999;

        $result = $this->transcriptionService->getTranscript($sermonId);

        $this->assertNull($result);
        $this->assertFalse($this->transcriptionService->transcriptExists($sermonId));
    }

    public function test_cleanup_on_failure_deletes_transcript()
    {
        $sermonId = 789;
        $transcript = 'Test transcript for cleanup.';

        // Store transcript first
        $this->transcriptionService->storeTranscript($sermonId, $transcript);
        $this->assertTrue($this->transcriptionService->transcriptExists($sermonId));

        // Cleanup on failure
        $this->transcriptionService->cleanupOnFailure($sermonId);

        $this->assertFalse($this->transcriptionService->transcriptExists($sermonId));
    }

    public function test_it_returns_correct_transcript_path_format()
    {
        $sermonId = 42;

        $path = $this->transcriptionService->getTranscriptPath($sermonId);

        $this->assertEquals('transcripts/sermon_42.md', $path);
    }

    public function test_it_logs_mock_operations()
    {
        // Set up more specific expectations for logging
        $this->mockLogger
            ->shouldReceive('logProcessingStep')
            ->with('test-id', 'mock_transcription', 'started', Mockery::type('array'))
            ->once();

        $this->mockLogger
            ->shouldReceive('logProcessingStep')
            ->with('test-id', 'mock_transcription', 'completed', Mockery::type('array'))
            ->once();

        // Create the default transcript file
        Storage::put('transcripts/sermon_7.md', 'Mock content for logging test.');

        $result = $this->transcriptionService->transcribe('test/path.mp3', 'test-id');

        // Add assertion to avoid risky test warning
        $this->assertEquals('Mock content for logging test.', $result);

        // Mockery will automatically verify the expectations
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
