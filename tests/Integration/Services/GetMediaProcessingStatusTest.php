<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Actions\GetMediaProcessingStatus;
use App\Enums\ProcessingStatus;
use App\Models\SermonProcessingStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\BuildsTestScenarios;

class GetMediaProcessingStatusTest extends TestCase
{
    use BuildsTestScenarios;
    use RefreshDatabase;

    #[Test]
    public function it_returns_durable_processing_diagnostics(): void
    {
        Carbon::setTestNow('2026-05-27 10:00:00');

        $admin = $this->actingAsVerifiedAdmin();
        $processingId = Str::uuid()->toString();

        $this->processingLogScenario()
            ->processing('transcription')
            ->state([
                'processing_id' => $processingId,
                'original_filename' => 'sermon.mp3',
                'processing_metadata' => ['video_processing_mode' => 'full_video'],
                'queue_name' => 'media-processing',
                'job_id' => 'job-123',
                'attempt_count' => 2,
            ])
            ->create();

        SermonProcessingStep::query()->create([
            'processing_id' => $processingId,
            'step' => 'ingestion',
            'status' => ProcessingStatus::Completed,
            'message' => 'Ingested',
            'started_at' => now()->subMinutes(2),
            'completed_at' => now()->subMinute(),
        ]);
        SermonProcessingStep::query()->create([
            'processing_id' => $processingId,
            'step' => 'transcription',
            'status' => ProcessingStatus::Started,
            'message' => 'Transcribing',
            'started_at' => now(),
        ]);

        $this->actingAs($admin);

        $response = app(GetMediaProcessingStatus::class)->getWithLogs($processingId, 10);

        $this->assertTrue($response->found);
        $this->assertSame($processingId, $response->processingId);
        $this->assertCount(2, $response->additionalData['processing_steps']);
        $this->assertSame('transcription', $response->additionalData['processing_steps'][0]['step']);
        $this->assertSame(60.0, $response->additionalData['processing_steps'][1]['duration_seconds']);
        $this->assertSame('media-processing', $response->additionalData['queue_name']);
        $this->assertSame('job-123', $response->additionalData['job_id']);
        $this->assertSame(2, $response->additionalData['attempt_count']);
        $this->assertSame(['video_processing_mode' => 'full_video'], $response->additionalData['processing_metadata']);
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
        $this->assertArrayNotHasKey('processing_steps', $response->additionalData);
        $this->assertArrayNotHasKey('processing_metadata', $response->additionalData);
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
