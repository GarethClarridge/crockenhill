<?php

namespace Tests\Feature\Health;

use App\HealthChecks\OpenAIHealthCheck;
use App\HealthChecks\SermonProcessingQueueHealthCheck;
use App\HealthChecks\StorageHealthCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reports_openai_health_success(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['models' => []], 200),
        ]);
        Config::set('openai.api_key', 'test-key');

        $check = new OpenAIHealthCheck();
        $result = $check->run();

        $this->assertEquals('healthy', $result['status']);
    }

    #[Test]
    public function it_reports_openai_health_failure_when_unconfigured(): void
    {
        Config::set('openai.api_key', null);

        $check = new OpenAIHealthCheck();
        $result = $check->run();

        $this->assertEquals('error', $result['status']);
        $this->assertStringContainsString('not configured', $result['message']);
    }

    #[Test]
    public function it_reports_storage_health_success(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        
        // Ensure the expected directories exist in the fake storage
        Storage::disk('local')->makeDirectory('transcripts');
        Storage::disk('public')->makeDirectory('sermons');

        $check = new StorageHealthCheck();
        $result = $check->run();

        $this->assertEquals('healthy', $result['status']);
    }

    #[Test]
    public function it_reports_queue_health_success(): void
    {
        \App\Models\MediaProcessingLog::factory()->count(1)->create(['status' => \App\Enums\ProcessingStatus::PENDING]);

        $check = new SermonProcessingQueueHealthCheck();
        $result = $check->run();

        $this->assertEquals('healthy', $result['status']);
    }
}
