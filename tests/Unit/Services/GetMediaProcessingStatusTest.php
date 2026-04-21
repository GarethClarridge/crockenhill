<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ProcessingStatus;
use App\Models\User;
use App\Services\GetMediaProcessingStatus;
use App\Services\ProcessingLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\BuildsTestScenarios;

class GetMediaProcessingStatusTest extends TestCase
{
    use BuildsTestScenarios;
    use RefreshDatabase;

    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = storage_path('logs/get-media-processing-status-'.Str::uuid().'.log');
        File::put($this->logFile, '');

        $this->app->bind(ProcessingLogService::class, fn (): ProcessingLogService => new ProcessingLogService($this->logFile));
    }

    protected function tearDown(): void
    {
        if (File::exists($this->logFile)) {
            File::delete($this->logFile);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_returns_processing_status_with_recent_logs_and_metrics(): void
    {
        $admin = $this->actingAsVerifiedAdmin();
        $processingId = Str::uuid()->toString();

        $this->processingLogScenario()
            ->processing('transcription')
            ->state([
                'processing_id' => $processingId,
                'original_filename' => 'sermon.mp3',
            ])
            ->create();

        File::put($this->logFile, implode("\n", [
            '[2026-03-17 10:00:00] local.INFO: Processing step: ingestion - started {"processing_id":"'.$processingId.'","step":"ingestion","status":"started","execution_time":1.5,"memory_usage":1048576}',
            '[2026-03-17 10:00:05] local.ERROR: Processing step: transcription - failed {"processing_id":"'.$processingId.'","step":"transcription","status":"failed","execution_time":2.75,"memory_usage":2097152,"error_message":"API timeout"}',
        ]));

        $this->actingAs($admin);

        $response = app(GetMediaProcessingStatus::class)->getWithLogs($processingId, 10);

        $this->assertTrue($response->found);
        $this->assertSame($processingId, $response->processingId);
        $this->assertNotNull($response->recentLogs);
        $this->assertCount(2, $response->recentLogs->entries);
        $this->assertSame('transcription', $response->recentLogs->entries->first()->step);
        $this->assertSame('error', $response->recentLogs->entries->first()->level);
        $this->assertSame('API timeout', $response->recentLogs->entries->first()->errorMessage);
        $this->assertNotNull($response->performanceMetrics);
        $this->assertSame(4.25, $response->performanceMetrics['total_execution_time']);
        $this->assertSame(2097152, $response->performanceMetrics['peak_memory_usage']);
    }

    #[Test]
    public function it_returns_a_status_response_without_logs(): void
    {
        $admin = $this->actingAsVerifiedAdmin();
        $processingId = Str::uuid()->toString();

        $this->processingLogScenario()
            ->processing('audio_transcription')
            ->state([
                'processing_id' => $processingId,
                'status' => ProcessingStatus::Processing,
            ])
            ->create();

        $this->actingAs($admin);

        $response = app(GetMediaProcessingStatus::class)->get($processingId);

        $this->assertTrue($response->found);
        $this->assertSame($processingId, $response->processingId);
        $this->assertSame('processing', $response->status);
        $this->assertSame('audio_transcription', $response->currentStep);
        $this->assertNull($response->recentLogs);
        $this->assertNull($response->performanceMetrics);
    }

    #[Test]
    public function it_returns_not_found_when_the_processing_id_does_not_exist(): void
    {
        $this->actingAsVerifiedAdmin();

        $response = app(GetMediaProcessingStatus::class)->get(Str::uuid()->toString());

        $this->assertFalse($response->found);
    }

    #[Test]
    public function it_reports_whether_a_processing_id_is_visible_to_the_current_user(): void
    {
        $owner = User::factory()->create(['is_admin' => false, 'email_verified_at' => now()]);
        $otherUser = User::factory()->create(['is_admin' => false, 'email_verified_at' => now()]);

        $visibleLog = $this->processingLogScenario()
            ->withOwner($owner)
            ->state(['processing_id' => Str::uuid()->toString()])
            ->create();

        $hiddenLog = $this->processingLogScenario()
            ->withOwner($otherUser)
            ->state(['processing_id' => Str::uuid()->toString()])
            ->create();

        $this->actingAs($owner);

        $service = app(GetMediaProcessingStatus::class);

        $this->assertTrue($service->canHandle($visibleLog->processing_id));
        $this->assertFalse($service->canHandle($hiddenLog->processing_id));
    }

    #[Test]
    public function it_only_returns_logs_visible_to_the_authenticated_non_admin_user(): void
    {
        $owner = User::factory()->create(['is_admin' => false, 'email_verified_at' => now()]);
        $otherUser = User::factory()->create(['is_admin' => false, 'email_verified_at' => now()]);

        $ownedLog = $this->processingLogScenario()
            ->withOwner($owner)
            ->state(['processing_id' => Str::uuid()->toString()])
            ->create();

        $hiddenLog = $this->processingLogScenario()
            ->withOwner($otherUser)
            ->state(['processing_id' => Str::uuid()->toString()])
            ->create();

        $this->actingAs($owner);

        $service = app(GetMediaProcessingStatus::class);

        $this->assertNotNull($service->find($ownedLog->processing_id));
        $this->assertNull($service->find($hiddenLog->processing_id));
    }
}
