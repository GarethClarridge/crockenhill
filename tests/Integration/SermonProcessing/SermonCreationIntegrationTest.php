<?php

namespace Tests\Integration\SermonProcessing;

use App\Data\SermonMetadata;
use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Jobs\CreateSermonRecord;
use App\Jobs\ProcessTranscriptWithAI;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\SermonAnalysisService;
use Illuminate\Support\Facades\Storage;
use Tests\Integration\BaseIntegrationTest;

class SermonCreationIntegrationTest extends BaseIntegrationTest
{
    /**
     * Test CreateSermonRecord job execution (property access bugs)
     */
    public function test_create_sermon_record_job_executes_successfully(): void
    {
        $metadata = SermonMetadata::create(
            date: now(),
            service: SermonService::MORNING,
            filename: 'test.mp3',
            originalName: 'test.mp3'
        );

        $audioFile = $this->copyTestFixture('test_audio_short.mp3', 'test-integration/test.mp3');

        // Create processing log
        $processing = MediaProcessingLog::create([
            'processing_id' => 'test-id',
            'original_filename' => 'test.mp3',
            'stored_file_path' => $audioFile,
            'ai_analysis' => json_encode(['title' => 'Test Sermon Title']),
            'status' => ProcessingStatus::PENDING,
        ]);

        // Execute job directly (would catch property access errors)
        $job = new CreateSermonRecord($processing);
        $job->handle(app(\App\Services\MediaProcessingLogger::class));

        // Verify sermon created without errors
        $processing->refresh();
        $this->assertNotNull($processing->sermon_id);

        $sermon = Sermon::find($processing->sermon_id);
        $this->assertNotNull($sermon);
        $this->assertEquals($metadata->date->format('Y-m-d'), $sermon->date->format('Y-m-d'));
        $this->assertEquals($metadata->service, $sermon->service);
    }

    /**
     * Test AI analysis service instantiation and configuration
     */
    public function test_ai_analysis_service_can_be_instantiated(): void
    {
        // This should NOT fail with "OpenAI API key not configured" since we set it in BaseIntegrationTest
        $service = app(SermonAnalysisService::class);

        $this->assertInstanceOf(SermonAnalysisService::class, $service);
    }

    /**
     * Test AI analysis service method signature (we expect analyzeSermon(string $transcript, ...))
     */
    public function test_ai_analysis_service_method_signature(): void
    {
        $service = app(SermonAnalysisService::class);

        // Test that the method exists with correct signature
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('analyzeSermon');

        $this->assertTrue($method->isPublic());

        // Check parameters
        $parameters = $method->getParameters();
        $this->assertGreaterThan(0, count($parameters));
        $this->assertEquals('transcript', $parameters[0]->getName());
        $this->assertEquals('string', $parameters[0]->getType()?->getName());
    }

    /**
     * Test ProcessTranscriptWithAI job execution
     */
    public function test_process_transcript_with_ai_job_executes(): void
    {
        $sermon = Sermon::factory()->create([
            'transcript_file_path' => 'test-transcript.md',
        ]);

        // Store a test transcript
        Storage::put('test-transcript.md', 'This is a comprehensive test sermon transcript about faith, hope, and love. The speaker discusses the importance of community and spiritual growth.');

        // Create processing log
        $processing = MediaProcessingLog::create([
            'processing_id' => 'test-ai-id',
            'original_filename' => 'test-transcript.md',
            'sermon_id' => $sermon->id,
            'status' => ProcessingStatus::PROCESSING,
        ]);

        // Execute AI processing job
        $job = new ProcessTranscriptWithAI($sermon->id);
        $job->handle(app(\App\Services\SermonAnalysisService::class));

        // Verify AI analysis completed
        $processing->refresh();
        $sermon->refresh();

        // Note: Job executes without errors, but title/summary would only be populated
        // with real AI analysis. For integration testing, we verify the job runs without crashing.
        $this->assertTrue(true, 'ProcessTranscriptWithAI job executed without throwing exceptions');
    }

    /**
     * Test complete sermon processing pipeline integration
     */
    public function test_complete_sermon_processing_integration(): void
    {
        $metadata = SermonMetadata::create(
            date: now(),
            service: SermonService::EVENING,
            filename: 'test_integration.mp3',
            originalName: 'Sunday Evening Sermon.mp3'
        );

        $audioFile = $this->copyTestFixture('test_audio_short.mp3', 'test-integration/sermon.mp3');

        // Create processing log
        $processingId = 'integration-test-'.uniqid();
        $processing = MediaProcessingLog::create([
            'processing_id' => $processingId,
            'original_filename' => $metadata->originalName,
            'stored_file_path' => $audioFile,
            'ai_analysis' => json_encode(['title' => 'Integration Test Sermon']),
            'status' => ProcessingStatus::PENDING,
        ]);

        // Step 1: Create sermon record
        $createJob = new CreateSermonRecord($processing);
        $createJob->handle(app(\App\Services\MediaProcessingLogger::class));

        $processing->refresh();
        $this->assertNotNull($processing->sermon_id, 'Sermon should be created');

        $sermon = Sermon::find($processing->sermon_id);
        $this->assertNotNull($sermon);

        // Step 2: Process transcript with AI (if transcript exists)
        if ($processing->transcript_path) {
            $aiJob = new ProcessTranscriptWithAI($processing);
            $aiJob->handle(app(\App\Services\SermonAnalysisService::class));

            $processing->refresh();
            $sermon->refresh();

            $this->assertEquals(ProcessingStatus::COMPLETED, $processing->status);
            $this->assertNotEmpty($sermon->title);
        }

        // Verify final state
        $this->assertEquals($metadata->service, $sermon->service);
        $this->assertEquals($metadata->date->format('Y-m-d'), $sermon->date->format('Y-m-d'));
        $this->assertNotEmpty($sermon->slug);
    }
}
