<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ProcessingLogCollection;
use App\Services\ProcessingLogService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ProcessingLogServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProcessingLogService $service;

    private string $testLogFile;

    protected function setUp(): void
    {
        parent::setUp();

        // Create unique log file for this test to avoid parallel test conflicts
        $testId = uniqid('unit_test_', true);
        $this->testLogFile = storage_path("logs/test-{$testId}.log");

        $this->service = new ProcessingLogService($this->testLogFile);

        // Initialize the test log file
        File::put($this->testLogFile, '');
    }

    protected function tearDown(): void
    {
        // Clean up unique test log file
        if (File::exists($this->testLogFile)) {
            File::delete($this->testLogFile);
        }

        parent::tearDown();
    }

    public function test_get_processing_logs_returns_empty_collection_when_no_logs_exist(): void
    {
        $result = $this->service->getProcessingLogs('test-processing-id');

        $this->assertInstanceOf(ProcessingLogCollection::class, $result);
        $this->assertTrue($result->isEmpty());
        $this->assertEquals(0, $result->totalCount);
    }

    public function test_get_processing_logs_returns_empty_collection_when_log_file_does_not_exist(): void
    {
        File::delete($this->testLogFile);

        $result = $this->service->getProcessingLogs('test-processing-id');

        $this->assertInstanceOf(ProcessingLogCollection::class, $result);
        $this->assertTrue($result->isEmpty());
    }

    public function test_get_processing_logs_parses_and_filters_logs_correctly(): void
    {
        $processingId = 'test-processing-id-123';
        $otherProcessingId = 'other-processing-id-456';

        // Create test log entries
        $logEntries = [
            '[2024-01-01 10:00:00] local.INFO: Processing step: initialization - started {"processing_id":"'.$processingId.'","step":"initialization","status":"started","memory_usage":1024000}',
            '[2024-01-01 10:00:05] local.ERROR: Processing step: transcription - failed {"processing_id":"'.$processingId.'","step":"transcription","status":"failed","error_message":"API timeout","execution_time":5.5}',
            '[2024-01-01 10:00:10] local.INFO: Processing step: analysis - completed {"processing_id":"'.$otherProcessingId.'","step":"analysis","status":"completed"}',
            '[2024-01-01 10:00:15] local.DEBUG: Processing step: cleanup - started {"processing_id":"'.$processingId.'","step":"cleanup","status":"started","memory_usage":2048000}',
        ];

        File::put($this->testLogFile, implode("\n", $logEntries));

        $result = $this->service->getProcessingLogs($processingId, 10);

        $this->assertInstanceOf(ProcessingLogCollection::class, $result);
        $this->assertEquals(3, $result->count()); // Should only include logs for our processing ID
        $this->assertNotNull($result->summary);

        $entries = $result->entries->toArray();

        // Verify first log entry (most recent first)
        $this->assertEquals('cleanup', $entries[0]->step);
        $this->assertEquals('debug', $entries[0]->level);
        $this->assertEquals(2048000, $entries[0]->memoryUsage);

        // Verify error log entry
        $errorEntry = collect($entries)->firstWhere('level', 'error');
        $this->assertNotNull($errorEntry);
        $this->assertEquals('transcription', $errorEntry->step);
        $this->assertEquals('API timeout', $errorEntry->errorMessage);
        $this->assertEquals(5.5, $errorEntry->executionTime);
    }

    public function test_get_logs_since_filters_by_timestamp(): void
    {
        $processingId = 'test-processing-id-123';
        $since = Carbon::createFromFormat('Y-m-d H:i:s', '2024-01-01 10:00:05');

        $logEntries = [
            '[2024-01-01 10:00:00] local.INFO: Old log {"processing_id":"'.$processingId.'","step":"old"}',
            '[2024-01-01 10:00:10] local.INFO: New log {"processing_id":"'.$processingId.'","step":"new"}',
            '[2024-01-01 10:00:15] local.INFO: Newer log {"processing_id":"'.$processingId.'","step":"newer"}',
        ];

        File::put($this->testLogFile, implode("\n", $logEntries));

        $result = $this->service->getLogsSince($processingId, $since);

        $this->assertEquals(2, $result->count());
        $entries = $result->entries->toArray();
        $this->assertEquals('newer', $entries[0]->step);
        $this->assertEquals('new', $entries[1]->step);
    }

    public function test_get_logs_by_step_filters_correctly(): void
    {
        $processingId = 'test-processing-id-123';

        $logEntries = [
            '[2024-01-01 10:00:00] local.INFO: Processing step: initialization - started {"processing_id":"'.$processingId.'","step":"initialization"}',
            '[2024-01-01 10:00:05] local.INFO: Processing step: transcription - started {"processing_id":"'.$processingId.'","step":"transcription"}',
            '[2024-01-01 10:00:10] local.INFO: Processing step: initialization - completed {"processing_id":"'.$processingId.'","step":"initialization"}',
        ];

        File::put($this->testLogFile, implode("\n", $logEntries));

        $result = $this->service->getLogsByStep($processingId, 'initialization');

        $this->assertEquals(2, $result->count());
        $entries = $result->entries->toArray();
        $this->assertEquals('initialization', $entries[0]->step);
        $this->assertEquals('initialization', $entries[1]->step);
    }

    public function test_get_performance_metrics_calculates_correctly(): void
    {
        $processingId = 'test-processing-id-123';

        $logEntries = [
            '[2024-01-01 10:00:00] local.INFO: Step 1 {"processing_id":"'.$processingId.'","execution_time":2.5,"memory_usage":1024000}',
            '[2024-01-01 10:00:05] local.ERROR: Step 2 {"processing_id":"'.$processingId.'","execution_time":1.5,"memory_usage":2048000}',
            '[2024-01-01 10:00:10] local.WARNING: Step 3 {"processing_id":"'.$processingId.'","execution_time":3.0,"memory_usage":1536000}',
        ];

        File::put($this->testLogFile, implode("\n", $logEntries));

        $metrics = $this->service->getPerformanceMetrics($processingId);

        $this->assertNotNull($metrics);
        $this->assertEquals(7.0, $metrics['total_execution_time']); // 2.5 + 1.5 + 3.0
        $this->assertEquals(2048000, $metrics['peak_memory_usage']); // Maximum memory
        $this->assertEquals(3, $metrics['total_entries']);
        $this->assertEquals(1, $metrics['error_count']);
        $this->assertEquals(1, $metrics['warning_count']);
    }

    public function test_log_parsing_handles_various_message_formats(): void
    {
        $processingId = 'test-processing-id-123';

        $logEntries = [
            // Standard processing step message
            '[2024-01-01 10:00:00] local.INFO: Processing step: transcription - started {"processing_id":"'.$processingId.'","step":"transcription"}',
            // API call message
            '[2024-01-01 10:00:05] local.INFO: API call to OpenAI: /v1/audio/transcriptions {"processing_id":"'.$processingId.'","status_code":200,"response_time_ms":1500}',
            // File operation message
            '[2024-01-01 10:00:10] local.INFO: File operation: upload {"processing_id":"'.$processingId.'","file_size_bytes":1048576,"file_size_human":"1.00 MB"}',
            // General message without explicit step
            '[2024-01-01 10:00:15] local.INFO: Sermon processing completed successfully {"processing_id":"'.$processingId.'"}',
        ];

        File::put($this->testLogFile, implode("\n", $logEntries));

        $result = $this->service->getProcessingLogs($processingId, 10);

        $this->assertEquals(4, $result->count());
        $entries = $result->entries->toArray();

        // Check step extraction
        $this->assertEquals('completion', $entries[0]->step); // "processing completed"
        $this->assertEquals('file_operation', $entries[1]->step); // "File operation"
        $this->assertEquals('api_call', $entries[2]->step); // "API call"
        $this->assertEquals('transcription', $entries[3]->step); // Explicit step

        // Check metrics extraction
        $fileOpEntry = $entries[1];
        $this->assertNotNull($fileOpEntry->metrics);
        $this->assertEquals(1048576, $fileOpEntry->metrics['file_size']['bytes']);
        $this->assertEquals('1.00 MB', $fileOpEntry->metrics['file_size']['human']);

        $apiEntry = $entries[2];
        $this->assertNotNull($apiEntry->metrics);
        $this->assertEquals(200, $apiEntry->metrics['api']['status_code']);
        $this->assertEquals(1.5, $apiEntry->executionTime); // 1500ms converted to seconds
    }

    public function test_log_summary_generates_correctly(): void
    {
        $processingId = 'test-processing-id-123';

        $logEntries = [
            '[2024-01-01 10:00:00] local.INFO: Step 1 {"processing_id":"'.$processingId.'","step":"initialization","execution_time":1.0}',
            '[2024-01-01 10:00:05] local.ERROR: Step 2 {"processing_id":"'.$processingId.'","step":"transcription","execution_time":2.0}',
            '[2024-01-01 10:00:10] local.WARNING: Step 3 {"processing_id":"'.$processingId.'","step":"initialization","execution_time":1.5}',
            '[2024-01-01 10:00:20] local.INFO: Step 4 {"processing_id":"'.$processingId.'","step":"completion","execution_time":0.5}',
        ];

        File::put($this->testLogFile, implode("\n", $logEntries));

        $result = $this->service->getProcessingLogs($processingId, 10);

        $summary = $result->summary;
        $this->assertNotNull($summary);

        // Check totals
        $this->assertEquals(4, $summary['total_entries']);

        // Check level counts
        $this->assertEquals(2, $summary['levels']['info']);
        $this->assertEquals(1, $summary['levels']['error']);
        $this->assertEquals(1, $summary['levels']['warning']);

        // Check step counts
        $this->assertEquals(2, $summary['steps']['initialization']);
        $this->assertEquals(1, $summary['steps']['transcription']);
        $this->assertEquals(1, $summary['steps']['completion']);

        // Check timespan
        $this->assertNotNull($summary['timespan']);
        $this->assertEquals(20, $summary['timespan']['duration_seconds']);

        // Check performance
        $this->assertNotNull($summary['performance']);
        $this->assertEquals(5.0, $summary['performance']['total_execution_time']);
        $this->assertEquals(1.25, $summary['performance']['average_step_time']);
        $this->assertEquals(2.0, $summary['performance']['max_step_time']);
    }

    public function test_respects_log_limit(): void
    {
        $processingId = 'test-processing-id-123';

        // Create 10 log entries
        $logEntries = [];
        for ($i = 0; $i < 10; $i++) {
            $logEntries[] = '[2024-01-01 10:00:'.sprintf('%02d', $i).'] local.INFO: Step '.$i.' {"processing_id":"'.$processingId.'","step":"step_'.$i.'"}';
        }

        File::put($this->testLogFile, implode("\n", $logEntries));

        $result = $this->service->getProcessingLogs($processingId, 5);

        $this->assertEquals(5, $result->count());
        $this->assertEquals(5, $result->totalCount);

        // Should get the most recent entries (highest timestamps first)
        $entries = $result->entries->toArray();
        $this->assertEquals('step_9', $entries[0]->step);
        $this->assertEquals('step_8', $entries[1]->step);
    }

    public function test_handles_malformed_log_entries_gracefully(): void
    {
        $processingId = 'test-processing-id-123';

        $logEntries = [
            // Valid entry
            '[2024-01-01 10:00:00] local.INFO: Valid entry {"processing_id":"'.$processingId.'","step":"valid"}',
            // Malformed timestamp
            '[invalid-timestamp] local.INFO: Invalid timestamp {"processing_id":"'.$processingId.'"}',
            // Malformed JSON
            '[2024-01-01 10:00:05] local.INFO: Invalid JSON {"processing_id":"'.$processingId.'","malformed":}',
            // No processing ID (should be filtered out)
            '[2024-01-01 10:00:10] local.INFO: No processing ID {"step":"no_id"}',
            // Another valid entry
            '[2024-01-01 10:00:15] local.INFO: Another valid entry {"processing_id":"'.$processingId.'","step":"valid2"}',
        ];

        File::put($this->testLogFile, implode("\n", $logEntries));

        $result = $this->service->getProcessingLogs($processingId, 10);

        // Should only return the valid entries
        $this->assertEquals(2, $result->count());
        $entries = $result->entries->toArray();
        $this->assertEquals('valid2', $entries[0]->step);
        $this->assertEquals('valid', $entries[1]->step);
    }
}
