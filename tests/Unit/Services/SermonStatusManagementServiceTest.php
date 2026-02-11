<?php

namespace Tests\Unit\Services;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\SermonStatusManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonStatusManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    private SermonStatusManagementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SermonStatusManagementService;
    }

    // --- getProcessingStatus() ---

    #[Test]
    public function it_returns_status_for_existing_processing_log(): void
    {
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'current_step' => 'transcribing_audio',
        ]);

        $response = $this->service->getProcessingStatus($log->processing_id);

        $this->assertTrue($response->found);
        $this->assertEquals($log->processing_id, $response->processingId);
        $this->assertEquals('processing', $response->status);
    }

    #[Test]
    public function it_returns_not_found_for_nonexistent_processing_id(): void
    {
        $response = $this->service->getProcessingStatus('nonexistent-id');

        $this->assertFalse($response->found);
    }

    #[Test]
    public function it_returns_completed_status_with_sermon_details(): void
    {
        $log = MediaProcessingLog::factory()->audio()->completed()->create();

        $response = $this->service->getProcessingStatus($log->processing_id);

        $this->assertTrue($response->found);
        $this->assertEquals('completed', $response->status);
        $this->assertNotNull($response->sermonId);
    }

    #[Test]
    public function it_returns_failed_status_with_error_message(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'error_message' => 'Transcription API timeout',
        ]);

        $response = $this->service->getProcessingStatus($log->processing_id);

        $this->assertTrue($response->found);
        $this->assertEquals('failed', $response->status);
        $this->assertEquals('Transcription API timeout', $response->errorMessage);
    }

    // --- getProcessingStatistics() ---

    #[Test]
    public function it_returns_processing_statistics_with_correct_counts(): void
    {
        MediaProcessingLog::factory()->audio()->completed()->count(5)->create();
        MediaProcessingLog::factory()->audio()->failed()->count(2)->create();
        MediaProcessingLog::factory()->audio()->processing()->count(1)->create();
        MediaProcessingLog::factory()->audio()->pending()->count(3)->create();

        $stats = $this->service->getProcessingStatistics();

        $this->assertEquals(11, $stats['total_processed']);
        $this->assertEquals(5, $stats['completed']);
        $this->assertEquals(2, $stats['failed']);
        $this->assertEquals(1, $stats['in_progress']);
        $this->assertEquals(3, $stats['pending']);
    }

    #[Test]
    public function it_returns_recent_activity_in_statistics(): void
    {
        MediaProcessingLog::factory()->audio()->completed()->count(3)->create();

        $stats = $this->service->getProcessingStatistics();

        $this->assertArrayHasKey('recent_activity', $stats);
        $this->assertCount(3, $stats['recent_activity']);
    }

    #[Test]
    public function it_returns_empty_statistics_when_no_records(): void
    {
        $stats = $this->service->getProcessingStatistics();

        $this->assertEquals(0, $stats['total_processed']);
        $this->assertEquals(0, $stats['completed']);
        $this->assertEquals(0, $stats['failed']);
    }

    // --- getFailedProcessingLogs() ---

    #[Test]
    public function it_returns_failed_processing_logs(): void
    {
        MediaProcessingLog::factory()->audio()->failed()->count(3)->create([
            'error_message' => 'Transcription failed',
        ]);
        MediaProcessingLog::factory()->audio()->completed()->count(2)->create();

        $logs = $this->service->getFailedProcessingLogs();

        $this->assertCount(3, $logs);
        $this->assertArrayHasKey('processing_id', $logs[0]);
        $this->assertArrayHasKey('error_message', $logs[0]);
        $this->assertArrayHasKey('can_retry', $logs[0]);
        $this->assertArrayHasKey('requires_manual_review', $logs[0]);
    }

    #[Test]
    public function it_respects_limit_on_failed_processing_logs(): void
    {
        MediaProcessingLog::factory()->audio()->failed()->count(10)->create();

        $logs = $this->service->getFailedProcessingLogs(5);

        $this->assertCount(5, $logs);
    }

    #[Test]
    public function it_returns_empty_array_when_no_failed_logs(): void
    {
        MediaProcessingLog::factory()->audio()->completed()->count(3)->create();

        $logs = $this->service->getFailedProcessingLogs();

        $this->assertEmpty($logs);
    }

    #[Test]
    public function it_includes_sermon_data_in_failed_logs_when_available(): void
    {
        $sermon = Sermon::factory()->create(['title' => 'Test Sermon']);
        MediaProcessingLog::factory()->audio()->failed()->create([
            'sermon_id' => $sermon->id,
        ]);

        $logs = $this->service->getFailedProcessingLogs();

        $this->assertNotNull($logs[0]['sermon']);
        $this->assertEquals('Test Sermon', $logs[0]['sermon']['title']);
    }

    // --- markForManualReview() ---

    #[Test]
    public function it_marks_processing_for_manual_review(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create();

        $result = $this->service->markForManualReview($log->processing_id, 'Needs attention');

        $this->assertTrue($result);

        $log->refresh();
        $this->assertEquals(ProcessingStatus::FAILED, $log->status);
        $this->assertEquals('manual_review_required', $log->current_step);
        $this->assertStringContainsString('Needs attention', $log->error_message);
    }

    #[Test]
    public function it_marks_for_review_with_default_message_when_no_note(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create();

        $this->service->markForManualReview($log->processing_id);

        $log->refresh();
        $this->assertEquals('Marked for manual review', $log->error_message);
    }

    #[Test]
    public function it_returns_false_when_marking_nonexistent_log_for_review(): void
    {
        $result = $this->service->markForManualReview('nonexistent-id');

        $this->assertFalse($result);
    }

    // --- getDetailedProcessingLogs() ---

    #[Test]
    public function it_returns_detailed_logs_for_existing_processing(): void
    {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => $sermon->id,
            'current_step' => 'transcribing_audio',
        ]);

        $details = $this->service->getDetailedProcessingLogs($log->processing_id);

        $this->assertTrue($details['found']);
        $this->assertArrayHasKey('processing_log', $details);
        $this->assertArrayHasKey('sermon', $details);
        $this->assertArrayHasKey('log_entries', $details);
        $this->assertArrayHasKey('troubleshooting', $details);
        $this->assertEquals($log->processing_id, $details['processing_log']['processing_id']);
    }

    #[Test]
    public function it_returns_not_found_for_nonexistent_detailed_logs(): void
    {
        $details = $this->service->getDetailedProcessingLogs('nonexistent-id');

        $this->assertFalse($details['found']);
    }

    #[Test]
    public function it_returns_null_sermon_in_detailed_logs_when_no_sermon(): void
    {
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'sermon_id' => null,
        ]);

        $details = $this->service->getDetailedProcessingLogs($log->processing_id);

        $this->assertTrue($details['found']);
        $this->assertNull($details['sermon']);
    }

    #[Test]
    public function it_generates_troubleshooting_info_for_transcription_failures(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'current_step' => 'transcribing_audio',
            'error_message' => 'API rate limit exceeded',
        ]);

        $details = $this->service->getDetailedProcessingLogs($log->processing_id);

        $troubleshooting = $details['troubleshooting'];
        $this->assertNotEmpty($troubleshooting['common_issues']);
        $this->assertNotEmpty($troubleshooting['suggested_actions']);
    }

    // --- getSystemHealth() ---

    #[Test]
    public function it_returns_system_health_with_required_keys(): void
    {
        $health = $this->service->getSystemHealth();

        $this->assertArrayHasKey('overall_status', $health);
        $this->assertArrayHasKey('checks', $health);
        $this->assertArrayHasKey('statistics', $health);
        $this->assertArrayHasKey('timestamp', $health);
    }

    #[Test]
    public function it_reports_healthy_status_when_all_checks_pass(): void
    {
        $health = $this->service->getSystemHealth();

        $this->assertEquals('healthy', $health['overall_status']);
    }

    #[Test]
    public function it_includes_queue_storage_and_processing_checks(): void
    {
        $health = $this->service->getSystemHealth();

        $this->assertArrayHasKey('queue', $health['checks']);
        $this->assertArrayHasKey('storage', $health['checks']);
        $this->assertArrayHasKey('processing', $health['checks']);
    }
}
