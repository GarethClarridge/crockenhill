<?php

namespace Tests\Unit\Services;

use App\Models\MediaProcessingLog;
use App\Services\LivestreamStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LivestreamStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    private LivestreamStatusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LivestreamStatusService;
    }

    #[Test]
    public function it_returns_processing_status_for_existing_record(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create();

        $response = $this->service->getProcessingStatus($log->processing_id);

        $this->assertEquals($log->processing_id, $response->processingId);
    }

    #[Test]
    public function it_throws_exception_for_nonexistent_processing_id(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Processing record not found');

        $this->service->getProcessingStatus('nonexistent-processing-id');
    }

    #[Test]
    public function it_returns_processing_result_for_existing_record(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'original_filename' => 'livestream-2026-01-15.mp4',
            'file_size' => 1073741824,
            'processing_metadata' => ['format' => 'mp4'],
        ]);

        $result = $this->service->getProcessingResult($log->processing_id);

        $this->assertEquals($log->processing_id, $result->processingId);
        $this->assertEquals('livestream-2026-01-15.mp4', $result->originalFilename);
    }

    #[Test]
    public function it_throws_exception_when_getting_result_for_nonexistent_record(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Processing record not found');

        $this->service->getProcessingResult('nonexistent-processing-id');
    }

    #[Test]
    public function it_returns_processing_summary_with_correct_counts(): void
    {
        MediaProcessingLog::factory()->livestream()->pending()->count(2)->create();
        MediaProcessingLog::factory()->livestream()->processing()->count(3)->create();
        MediaProcessingLog::factory()->livestream()->completed()->count(4)->create();
        MediaProcessingLog::factory()->livestream()->failed()->count(1)->create();

        $summary = $this->service->getProcessingSummary();

        $this->assertEquals(10, $summary['total_processing_requests']);
        $this->assertEquals(2, $summary['pending']);
        $this->assertEquals(3, $summary['processing']);
        $this->assertEquals(4, $summary['completed']);
        $this->assertEquals(1, $summary['failed']);
    }

    #[Test]
    public function it_calculates_success_rate_correctly(): void
    {
        MediaProcessingLog::factory()->livestream()->completed()->count(8)->create();
        MediaProcessingLog::factory()->livestream()->failed()->count(2)->create();

        $summary = $this->service->getProcessingSummary();

        $this->assertEquals(80.0, $summary['success_rate']);
    }

    #[Test]
    public function it_returns_zero_success_rate_when_no_records(): void
    {
        $summary = $this->service->getProcessingSummary();

        $this->assertEquals(0, $summary['success_rate']);
        $this->assertEquals(0, $summary['total_processing_requests']);
    }

    #[Test]
    public function processing_summary_excludes_non_livestream_logs(): void
    {
        MediaProcessingLog::factory()->audio()->completed()->count(5)->create();
        MediaProcessingLog::factory()->livestream()->completed()->count(2)->create();

        $summary = $this->service->getProcessingSummary();

        $this->assertEquals(2, $summary['total_processing_requests']);
        $this->assertEquals(2, $summary['completed']);
    }

    #[Test]
    public function it_returns_processing_result_with_error_message_when_failed(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->failed()->create([
            'error_message' => 'RMS generation failed',
            'file_size' => 1073741824,
            'processing_metadata' => ['format' => 'mp4'],
        ]);

        $result = $this->service->getProcessingResult($log->processing_id);

        $this->assertEquals('failed', $result->status);
        $this->assertEquals('RMS generation failed', $result->errorMessage);
    }

    #[Test]
    public function processing_summary_structure_has_required_keys(): void
    {
        $summary = $this->service->getProcessingSummary();

        $this->assertArrayHasKey('total_processing_requests', $summary);
        $this->assertArrayHasKey('pending', $summary);
        $this->assertArrayHasKey('processing', $summary);
        $this->assertArrayHasKey('completed', $summary);
        $this->assertArrayHasKey('failed', $summary);
        $this->assertArrayHasKey('success_rate', $summary);
    }
}
