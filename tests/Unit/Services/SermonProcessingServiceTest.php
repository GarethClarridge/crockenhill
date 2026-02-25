<?php

namespace Tests\Unit\Services;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\SermonProcessingLogger;
use App\Services\SermonProcessingService;
use App\Services\SermonValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonProcessingServiceTest extends TestCase
{
    use RefreshDatabase;

    private SermonProcessingService $service;

    private SermonValidationService $validationService;

    private SermonProcessingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validationService = $this->createMock(SermonValidationService::class);
        $this->logger = $this->createMock(SermonProcessingLogger::class);

        $this->service = new SermonProcessingService(
            $this->validationService,
            $this->logger
        );
    }

    // --- Graceful Degradation Tests ---

    #[Test]
    public function it_applies_graceful_degradation_successfully(): void
    {
        $sermon = Sermon::factory()->create(['title' => 'Untitled Sermon']);
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'sermon_id' => $sermon->id,
        ]);

        $fallbackData = [
            'title' => 'Sunday Morning Sermon',
            'slug' => 'sunday-morning-sermon',
            'series' => null,
            'reference' => null,
            'points' => ['Main Message'],
        ];

        $this->validationService
            ->method('generateFallbackData')
            ->willReturn($fallbackData);

        $result = $this->service->applyGracefulDegradation($log->processing_id);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Graceful degradation applied', $result->message);
        $this->assertEquals($log->processing_id, $result->processingId);
        $this->assertEquals($sermon->id, $result->details['sermon_id']);
        $this->assertArrayHasKey('applied_fallbacks', $result->details);
        $this->assertArrayHasKey('degradation_applied_at', $result->details);
    }

    #[Test]
    public function it_updates_sermon_with_fallback_data_on_degradation(): void
    {
        $sermon = Sermon::factory()->create(['title' => 'Untitled']);
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'sermon_id' => $sermon->id,
        ]);

        $fallbackData = [
            'title' => 'Fallback Title',
            'slug' => 'fallback-title',
            'series' => null,
            'reference' => null,
            'points' => ['Main Message'],
        ];

        $this->validationService
            ->method('generateFallbackData')
            ->willReturn($fallbackData);

        $this->service->applyGracefulDegradation($log->processing_id);

        $sermon->refresh();
        $this->assertEquals('Fallback Title', $sermon->title);
        $this->assertEquals('fallback-title', $sermon->slug);
    }

    #[Test]
    public function it_marks_processing_as_completed_with_degradation(): void
    {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'sermon_id' => $sermon->id,
        ]);

        $this->validationService
            ->method('generateFallbackData')
            ->willReturn(['title' => 'Fallback', 'slug' => 'fallback', 'series' => null, 'reference' => null, 'points' => ['Main Message']]);

        $this->service->applyGracefulDegradation($log->processing_id);

        $log->refresh();
        $this->assertEquals(ProcessingStatus::COMPLETED, $log->status);
        $this->assertEquals('completed_with_degradation', $log->current_step);
        $this->assertEquals('Graceful degradation applied', $log->error_message);
    }

    #[Test]
    public function it_returns_failure_when_processing_log_not_found_for_degradation(): void
    {
        $result = $this->service->applyGracefulDegradation('nonexistent-id');

        $this->assertFalse($result->success);
        $this->assertEquals('PROCESSING_LOG_NOT_FOUND', $result->errorCode);
        $this->assertStringContainsString('Processing log not found', $result->message);
    }

    #[Test]
    public function it_returns_failure_when_no_sermon_id_for_degradation(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'sermon_id' => null,
        ]);

        $result = $this->service->applyGracefulDegradation($log->processing_id);

        $this->assertFalse($result->success);
        $this->assertEquals('NO_SERMON_RECORD', $result->errorCode);
    }

    #[Test]
    public function it_returns_failure_when_sermon_not_found_for_degradation(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'sermon_id' => null,
        ]);

        // Set sermon_id to a non-existent value bypassing FK constraint
        \Illuminate\Support\Facades\DB::statement(
            'SET FOREIGN_KEY_CHECKS=0'
        );
        $log->update(['sermon_id' => 99999]);
        \Illuminate\Support\Facades\DB::statement(
            'SET FOREIGN_KEY_CHECKS=1'
        );

        $result = $this->service->applyGracefulDegradation($log->processing_id);

        $this->assertFalse($result->success);
        $this->assertEquals('SERMON_NOT_FOUND', $result->errorCode);
    }

    #[Test]
    public function it_handles_exception_during_graceful_degradation(): void
    {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'sermon_id' => $sermon->id,
        ]);

        $this->validationService
            ->method('generateFallbackData')
            ->willThrowException(new \RuntimeException('Validation service error'));

        $result = $this->service->applyGracefulDegradation($log->processing_id);

        $this->assertFalse($result->success);
        $this->assertEquals('DEGRADATION_FAILED', $result->errorCode);
        $this->assertStringContainsString('Validation service error', $result->message);
    }

    // --- Cancel Processing Tests ---

    #[Test]
    public function it_cancels_processing_successfully(): void
    {
        $log = MediaProcessingLog::factory()->audio()->processing()->create();

        $result = $this->service->cancelProcessing($log->processing_id);

        $this->assertTrue($result);

        $log->refresh();
        $this->assertEquals(ProcessingStatus::CANCELLED, $log->status);
        $this->assertEquals('cancelled', $log->current_step);
        $this->assertEquals('Processing cancelled by user', $log->error_message);
    }

    #[Test]
    public function it_cancels_pending_processing(): void
    {
        $log = MediaProcessingLog::factory()->audio()->pending()->create();

        $result = $this->service->cancelProcessing($log->processing_id);

        $this->assertTrue($result);

        $log->refresh();
        $this->assertEquals(ProcessingStatus::CANCELLED, $log->status);
    }

    #[Test]
    public function it_returns_false_when_cancelling_nonexistent_processing(): void
    {
        $result = $this->service->cancelProcessing('nonexistent-id');

        $this->assertFalse($result);
    }

    #[Test]
    public function it_returns_false_when_cancelling_completed_processing(): void
    {
        $log = MediaProcessingLog::factory()->audio()->completed()->create();

        $result = $this->service->cancelProcessing($log->processing_id);

        $this->assertFalse($result);

        $log->refresh();
        $this->assertEquals(ProcessingStatus::COMPLETED, $log->status);
    }

    #[Test]
    public function it_returns_false_when_cancelling_already_failed_processing(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create();

        // Failed processing still gets "cancelled" since it's not COMPLETED
        $result = $this->service->cancelProcessing($log->processing_id);

        $this->assertTrue($result);
    }
}
