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
        Storage::fake('local');
        config(['media-processing.storage.transcript_disk' => 'local']);

        $this->mockLogger = Mockery::mock(SermonProcessingLogger::class);
        $this->mockLogger->shouldReceive('logProcessingStep')->withAnyArgs()->byDefault();
        $this->transcriptionService = new MockTranscriptionService($this->mockLogger);
    }

    public function test_it_returns_in_code_mock_content_for_transcription()
    {
        $result = $this->transcriptionService->transcribe('fake/audio/path.mp3', 'test-processing-id');

        $this->assertIsString($result);
        $this->assertStringContainsString('Romans chapter 8, verse 28', $result);
        $this->assertStringContainsString('God works for the good of those who love him', $result);
    }

    public function test_it_does_not_depend_on_default_transcript_file_for_transcription()
    {
        Storage::delete('transcripts/sermon_7.md');

        $result = $this->transcriptionService->transcribe('fake/audio/path.mp3', 'test-processing-id');

        $this->assertIsString($result);
        $this->assertStringContainsString('Romans chapter 8, verse 28', $result);
        $this->assertStringContainsString('God works for the good of those who love him', $result);
        $this->assertFalse(Storage::exists('transcripts/sermon_7.md'));
    }

    public function test_it_can_store_and_retrieve_transcript()
    {
        $sermonId = 123;
        $transcript = 'This is a test transcript content.';

        // Store transcript
        $filePath = $this->transcriptionService->storeTranscript($sermonId, $transcript);

        $this->assertStringContainsString('sermon_123.md', $filePath);
        $this->assertTrue(Storage::disk('local')->exists($filePath));

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

        $result = $this->transcriptionService->transcribe('test/path.mp3', 'test-id');

        $this->assertStringContainsString('Romans chapter 8, verse 28', $result);

        // Mockery will automatically verify the expectations
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
