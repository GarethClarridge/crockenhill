<?php

namespace Tests\Unit\Services;

use App\Models\MediaProcessingLog;
use App\Services\LivestreamMonitoringService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LivestreamMonitoringServiceTest extends TestCase
{
    use RefreshDatabase;

    private LivestreamMonitoringService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LivestreamMonitoringService;
        Cache::flush();
    }

    #[Test]
    public function it_returns_metrics_with_expected_structure(): void
    {
        $metrics = $this->service->getProcessingMetrics(24);

        $this->assertArrayHasKey('summary', $metrics);
        $this->assertArrayHasKey('processing_times', $metrics);
        $this->assertArrayHasKey('failure_analysis', $metrics);
        $this->assertArrayHasKey('segmentation_stats', $metrics);
        $this->assertArrayHasKey('storage_usage', $metrics);
        $this->assertArrayHasKey('manual_review_rate', $metrics);
    }

    #[Test]
    public function it_counts_processing_records_within_time_window(): void
    {
        Cache::flush();

        MediaProcessingLog::factory()->livestream()->completed()->create([
            'created_at' => Carbon::now()->subHours(12),
        ]);
        MediaProcessingLog::factory()->livestream()->failed()->create([
            'created_at' => Carbon::now()->subHours(6),
        ]);
        // Outside window
        MediaProcessingLog::factory()->livestream()->completed()->create([
            'created_at' => Carbon::now()->subHours(48),
        ]);

        $metrics = $this->service->getProcessingMetrics(24);

        $this->assertEquals(2, $metrics['summary']['total_processed']);
        $this->assertEquals(1, $metrics['summary']['successful']);
        $this->assertEquals(1, $metrics['summary']['failed']);
    }

    #[Test]
    public function it_calculates_success_rate_correctly(): void
    {
        Cache::flush();

        MediaProcessingLog::factory()->livestream()->completed()->count(3)->create([
            'created_at' => Carbon::now()->subHours(1),
        ]);
        MediaProcessingLog::factory()->livestream()->failed()->count(1)->create([
            'created_at' => Carbon::now()->subHours(1),
        ]);

        $metrics = $this->service->getProcessingMetrics(24);

        $this->assertEquals(75.0, $metrics['summary']['success_rate']);
    }

    #[Test]
    public function it_returns_zero_success_rate_when_no_records(): void
    {
        Cache::flush();

        $metrics = $this->service->getProcessingMetrics(24);

        $this->assertEquals(0, $metrics['summary']['success_rate']);
        $this->assertEquals(0, $metrics['summary']['total_processed']);
    }

    #[Test]
    public function processing_times_structure_has_required_keys(): void
    {
        Cache::flush();

        $metrics = $this->service->getProcessingMetrics(24);

        $this->assertArrayHasKey('average_duration_minutes', $metrics['processing_times']);
        $this->assertArrayHasKey('median_duration_minutes', $metrics['processing_times']);
        $this->assertArrayHasKey('min_duration_minutes', $metrics['processing_times']);
        $this->assertArrayHasKey('max_duration_minutes', $metrics['processing_times']);
    }

    #[Test]
    public function failure_analysis_structure_has_required_keys(): void
    {
        Cache::flush();

        $metrics = $this->service->getProcessingMetrics(24);

        $this->assertArrayHasKey('total_failures', $metrics['failure_analysis']);
        $this->assertArrayHasKey('failure_types', $metrics['failure_analysis']);
        $this->assertArrayHasKey('recent_failures', $metrics['failure_analysis']);
    }

    #[Test]
    public function it_excludes_non_livestream_records_from_metrics(): void
    {
        Cache::flush();

        MediaProcessingLog::factory()->audio()->completed()->count(10)->create([
            'created_at' => Carbon::now()->subHours(1),
        ]);
        MediaProcessingLog::factory()->livestream()->completed()->count(2)->create([
            'created_at' => Carbon::now()->subHours(1),
        ]);

        $metrics = $this->service->getProcessingMetrics(24);

        $this->assertEquals(2, $metrics['summary']['total_processed']);
    }

    #[Test]
    public function it_caches_metrics_result(): void
    {
        Cache::flush();

        $this->service->getProcessingMetrics(24);

        $cacheKey = 'livestream_metrics_24h_'.now()->format('H');
        $this->assertTrue(Cache::has($cacheKey));
    }

    #[Test]
    public function it_returns_system_health_with_expected_structure(): void
    {
        $health = $this->service->getSystemHealth();

        $this->assertArrayHasKey('queue_health', $health);
        $this->assertArrayHasKey('storage_health', $health);
        $this->assertArrayHasKey('processing_health', $health);
    }

    #[Test]
    public function it_returns_performance_trends_with_expected_structure(): void
    {
        $trends = $this->service->getPerformanceTrends(7);

        $this->assertIsArray($trends);
    }

    #[Test]
    public function it_generates_alerts_as_array(): void
    {
        $alerts = $this->service->generateAlerts();

        $this->assertIsArray($alerts);
    }

    #[Test]
    public function it_counts_in_progress_records(): void
    {
        Cache::flush();

        MediaProcessingLog::factory()->livestream()->processing()->count(2)->create([
            'created_at' => Carbon::now()->subHours(1),
        ]);

        $metrics = $this->service->getProcessingMetrics(24);

        $this->assertEquals(2, $metrics['summary']['in_progress']);
    }
}
