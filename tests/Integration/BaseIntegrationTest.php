<?php

namespace Tests\Integration;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

abstract class BaseIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $testUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up real storage (not fakes)
        Storage::disk('local')->deleteDirectory('test-integration');
        Storage::disk('local')->makeDirectory('test-integration');

        // Configure for integration testing
        Config::set([
            'queue.default' => 'sync', // Execute jobs synchronously
            'transcription.service_type' => 'mock', // Use mock transcription
            'media-processing.transcription.service_type' => 'mock',
            'media-processing.transcription.openai_api_key' => 'test-key',
            'media-processing.analysis.openai_api_key' => 'test-key',
            'openai.api_key' => 'test-key', // Fallback key
            'media-processing.rms_threshold' => -30.0,
            'media-processing.min_sermon_duration' => 60.0,
            'media-processing.min_section_duration' => 30.0,
        ]);

        // Create test user
        $this->testUser = User::factory()->create();

        // Ensure queue workers are not running (we want sync execution)
        $this->artisan('queue:clear');
    }

    protected function tearDown(): void
    {
        // Cleanup test files
        Storage::disk('local')->deleteDirectory('test-integration');
        parent::tearDown();
    }

    /**
     * Copy test fixture file to storage for testing
     */
    protected function copyTestFixture(string $fixtureName, string $targetPath): string
    {
        $fixturePath = base_path("tests/Integration/fixtures/$fixtureName");
        $fullTargetPath = Storage::disk('local')->path($targetPath);

        if (! file_exists($fixturePath)) {
            throw new \RuntimeException("Test fixture not found: $fixturePath");
        }

        copy($fixturePath, $fullTargetPath);

        return $targetPath;
    }

    /**
     * Wait for all queued jobs to complete (for async testing)
     */
    protected function processQueuedJobs(): void
    {
        $maxAttempts = 30; // 30 seconds max
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $this->artisan('queue:work', ['--once' => true]);

            if (Queue::size() === 0) {
                break;
            }

            sleep(1);
            $attempt++;
        }

        if ($attempt >= $maxAttempts) {
            throw new \RuntimeException('Queue processing timeout');
        }
    }
}
