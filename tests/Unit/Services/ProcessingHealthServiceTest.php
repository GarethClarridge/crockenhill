<?php

namespace Tests\Unit\Services;

use App\Models\MediaProcessingLog;
use App\Services\ProcessingHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessingHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProcessingHealthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProcessingHealthService;
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
    public function it_calculates_success_rate_correctly(): void
    {
        MediaProcessingLog::factory()->audio()->completed()->count(8)->create();
        MediaProcessingLog::factory()->audio()->failed()->count(2)->create();

        $stats = $this->service->getProcessingStatistics();

        $this->assertEquals(80.0, $stats['success_rate']);
    }

    #[Test]
    public function it_returns_zero_success_rate_when_no_records(): void
    {
        $stats = $this->service->getProcessingStatistics();

        $this->assertEquals(0, $stats['success_rate']);
    }

    #[Test]
    public function it_includes_recent_activity_in_statistics(): void
    {
        MediaProcessingLog::factory()->audio()->completed()->count(3)->create();

        $stats = $this->service->getProcessingStatistics();

        $this->assertArrayHasKey('recent_activity', $stats);
        $this->assertCount(3, $stats['recent_activity']);
    }

    #[Test]
    public function it_limits_recent_activity_to_ten_entries(): void
    {
        MediaProcessingLog::factory()->audio()->completed()->count(15)->create();

        $stats = $this->service->getProcessingStatistics();

        $this->assertCount(10, $stats['recent_activity']);
    }

    // --- getSystemHealth() ---

    #[Test]
    public function it_returns_system_health_structure(): void
    {
        $health = $this->service->getSystemHealth();

        $this->assertArrayHasKey('overall_status', $health);
        $this->assertArrayHasKey('checks', $health);
        $this->assertArrayHasKey('statistics', $health);
        $this->assertArrayHasKey('timestamp', $health);
    }

    #[Test]
    public function it_reports_healthy_when_all_checks_pass(): void
    {
        $health = $this->service->getSystemHealth();

        $this->assertEquals('healthy', $health['overall_status']);
    }

    #[Test]
    public function it_includes_all_health_check_types(): void
    {
        $health = $this->service->getSystemHealth();

        $this->assertArrayHasKey('queue', $health['checks']);
        $this->assertArrayHasKey('storage', $health['checks']);
        $this->assertArrayHasKey('processing', $health['checks']);
    }

    // --- checkQueueHealth() ---

    #[Test]
    public function it_reports_healthy_queue_with_no_stuck_jobs(): void
    {
        $health = $this->service->checkQueueHealth();

        $this->assertEquals('healthy', $health['status']);
        $this->assertEquals(0, $health['stuck_jobs']);
    }

    #[Test]
    public function it_reports_degraded_queue_with_stuck_jobs(): void
    {
        MediaProcessingLog::factory()->audio()->processing()->create([
            'updated_at' => now()->subHours(3),
        ]);

        $health = $this->service->checkQueueHealth();

        $this->assertEquals('degraded', $health['status']);
        $this->assertEquals(1, $health['stuck_jobs']);
        $this->assertNotEmpty($health['issues']);
    }

    #[Test]
    public function it_reports_degraded_queue_with_many_pending_jobs(): void
    {
        MediaProcessingLog::factory()->audio()->pending()->count(12)->create();

        $health = $this->service->checkQueueHealth();

        $this->assertEquals('degraded', $health['status']);
        $this->assertEquals(12, $health['pending_jobs']);
    }

    // --- checkStorageHealth() ---

    #[Test]
    public function it_reports_healthy_storage(): void
    {
        $health = $this->service->checkStorageHealth();

        $this->assertEquals('healthy', $health['status']);
        $this->assertArrayHasKey('disk', $health);
    }

    // --- checkProcessingHealth() ---

    #[Test]
    public function it_reports_healthy_processing_with_high_success_rate(): void
    {
        MediaProcessingLog::factory()->audio()->completed()->count(9)->create();
        MediaProcessingLog::factory()->audio()->failed()->count(1)->create();

        $health = $this->service->checkProcessingHealth();

        $this->assertEquals('healthy', $health['status']);
        $this->assertEquals(90.0, $health['success_rate']);
    }

    #[Test]
    public function it_reports_degraded_processing_with_low_success_rate(): void
    {
        MediaProcessingLog::factory()->audio()->completed()->count(3)->create();
        MediaProcessingLog::factory()->audio()->failed()->count(7)->create();

        $health = $this->service->checkProcessingHealth();

        $this->assertEquals('degraded', $health['status']);
        $this->assertEquals(30.0, $health['success_rate']);
    }

    #[Test]
    public function it_reports_healthy_when_no_recent_processing(): void
    {
        $health = $this->service->checkProcessingHealth();

        $this->assertEquals('healthy', $health['status']);
    }

    #[Test]
    public function it_flags_many_failures_as_degraded(): void
    {
        MediaProcessingLog::factory()->audio()->completed()->count(5)->create();
        MediaProcessingLog::factory()->audio()->failed()->count(6)->create();

        $health = $this->service->checkProcessingHealth();

        $this->assertEquals('degraded', $health['status']);
        $this->assertNotEmpty($health['issues']);
    }
}
