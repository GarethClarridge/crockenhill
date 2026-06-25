<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ProcessingReport;
use App\Enums\ProcessingStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ProcessingReportTest extends TestCase
{
    // --- toArray() ---

    #[Test]
    public function it_returns_data_as_array(): void
    {
        $data = ['status' => 'completed', 'total_segments' => 3];
        $report = new ProcessingReport($data);

        $this->assertEquals($data, $report->toArray());
    }

    // --- toJson() ---

    #[Test]
    public function it_returns_data_as_pretty_json(): void
    {
        $data = ['status' => 'completed', 'total_segments' => 3];
        $report = new ProcessingReport($data);

        $json = $report->toJson();

        $this->assertJson($json);
        $this->assertEquals($data, json_decode($json, true));
        $this->assertStringContainsString("\n", $json);
    }

    // --- getStatus() ---

    #[Test]
    public function it_returns_string_status(): void
    {
        $report = new ProcessingReport(['status' => 'completed']);

        $this->assertEquals('completed', $report->getStatus());
    }

    #[Test]
    public function it_returns_unknown_when_status_is_missing(): void
    {
        $report = new ProcessingReport([]);

        $this->assertEquals('unknown', $report->getStatus());
    }

    #[Test]
    public function it_handles_processing_status_enum(): void
    {
        $report = new ProcessingReport(['status' => ProcessingStatus::Completed]);

        $this->assertEquals('completed', $report->getStatus());
    }

    #[Test]
    public function it_handles_failed_processing_status_enum(): void
    {
        $report = new ProcessingReport(['status' => ProcessingStatus::Failed]);

        $this->assertEquals('failed', $report->getStatus());
    }

    // --- hasErrors() ---

    #[Test]
    public function it_returns_true_when_errors_exist(): void
    {
        $report = new ProcessingReport(['errors' => ['Something failed']]);

        $this->assertTrue($report->hasErrors());
    }

    #[Test]
    public function it_returns_false_when_no_errors(): void
    {
        $report = new ProcessingReport(['errors' => []]);

        $this->assertFalse($report->hasErrors());
    }

    #[Test]
    public function it_returns_false_when_errors_key_is_missing(): void
    {
        $report = new ProcessingReport([]);

        $this->assertFalse($report->hasErrors());
    }

    // --- hasWarnings() ---

    #[Test]
    public function it_returns_true_when_warnings_exist(): void
    {
        $report = new ProcessingReport(['warnings' => ['Low quality audio']]);

        $this->assertTrue($report->hasWarnings());
    }

    #[Test]
    public function it_returns_false_when_no_warnings(): void
    {
        $report = new ProcessingReport(['warnings' => []]);

        $this->assertFalse($report->hasWarnings());
    }

    // --- getProcessingDuration() ---

    #[Test]
    public function it_returns_processing_duration_when_set(): void
    {
        $report = new ProcessingReport(['processing_duration_seconds' => 120]);

        $this->assertEquals(120, $report->getProcessingDuration());
    }

    #[Test]
    public function it_returns_null_when_processing_duration_is_missing(): void
    {
        $report = new ProcessingReport([]);

        $this->assertNull($report->getProcessingDuration());
    }

    // --- getSegmentCount() ---

    #[Test]
    public function it_returns_segment_count_when_set(): void
    {
        $report = new ProcessingReport(['total_segments' => 5]);

        $this->assertEquals(5, $report->getSegmentCount());
    }

    #[Test]
    public function it_returns_zero_when_segment_count_is_missing(): void
    {
        $report = new ProcessingReport([]);

        $this->assertEquals(0, $report->getSegmentCount());
    }
}
