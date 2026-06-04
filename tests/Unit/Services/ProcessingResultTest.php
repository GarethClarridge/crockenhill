<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ProcessingResult;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessingResultTest extends TestCase
{
    // --- success() factory ---

    #[Test]
    public function it_creates_a_success_result_with_required_fields(): void
    {
        $result = ProcessingResult::success(
            processingId: 'proc-123',
            message: 'Processing started'
        );

        $this->assertTrue($result->success);
        $this->assertEquals('proc-123', $result->processingId);
        $this->assertEquals('Processing started', $result->message);
        $this->assertNull($result->statusUrl);
        $this->assertNull($result->errorCode);
        $this->assertNull($result->details);
    }

    #[Test]
    public function it_creates_a_success_result_with_status_url(): void
    {
        $result = ProcessingResult::success(
            processingId: 'proc-123',
            message: 'Processing started',
            statusUrl: 'https://example.com/status/proc-123'
        );

        $this->assertEquals('https://example.com/status/proc-123', $result->statusUrl);
    }

    #[Test]
    public function it_creates_a_success_result_with_details(): void
    {
        $details = ['sermon_id' => 42, 'duration' => 3600];

        $result = ProcessingResult::success(
            processingId: 'proc-123',
            message: 'Processing complete',
            details: $details
        );

        $this->assertEquals($details, $result->details);
    }

    // --- failure() factory ---

    #[Test]
    public function it_creates_a_failure_result(): void
    {
        $result = ProcessingResult::failure(
            processingId: 'proc-456',
            message: 'Something went wrong',
            errorCode: 'AUDIO_PROCESSING_FAILED'
        );

        $this->assertFalse($result->success);
        $this->assertEquals('proc-456', $result->processingId);
        $this->assertEquals('Something went wrong', $result->message);
        $this->assertEquals('AUDIO_PROCESSING_FAILED', $result->errorCode);
    }

    // --- toArray() ---

    #[Test]
    public function it_converts_success_result_to_array_with_minimal_fields(): void
    {
        $result = ProcessingResult::success(
            processingId: 'proc-123',
            message: 'Started'
        );

        $array = $result->toArray();

        $this->assertEquals([
            'success' => true,
            'message' => 'Started',
            'processing_id' => 'proc-123',
        ], $array);
    }

    #[Test]
    public function it_includes_status_url_in_array_when_present(): void
    {
        $result = ProcessingResult::success(
            processingId: 'proc-123',
            message: 'Started',
            statusUrl: 'https://example.com/status'
        );

        $array = $result->toArray();

        $this->assertArrayHasKey('status_url', $array);
        $this->assertEquals('https://example.com/status', $array['status_url']);
    }

    #[Test]
    public function it_includes_error_code_in_array_when_present(): void
    {
        $result = ProcessingResult::failure(
            processingId: 'proc-456',
            message: 'Failed',
            errorCode: 'TIMEOUT'
        );

        $array = $result->toArray();

        $this->assertArrayHasKey('error_code', $array);
        $this->assertEquals('TIMEOUT', $array['error_code']);
    }

    #[Test]
    public function it_includes_details_in_array_when_present(): void
    {
        $details = ['step' => 'transcription', 'duration' => 120];

        $result = ProcessingResult::success(
            processingId: 'proc-123',
            message: 'Done',
            details: $details
        );

        $array = $result->toArray();

        $this->assertArrayHasKey('details', $array);
        $this->assertEquals($details, $array['details']);
    }

    #[Test]
    public function it_omits_null_optional_fields_from_array(): void
    {
        $result = ProcessingResult::success(
            processingId: 'proc-123',
            message: 'Done'
        );

        $array = $result->toArray();

        $this->assertArrayNotHasKey('status_url', $array);
        $this->assertArrayNotHasKey('error_code', $array);
        $this->assertArrayNotHasKey('details', $array);
    }

    #[Test]
    public function it_converts_failure_result_to_complete_array(): void
    {
        $result = ProcessingResult::failure(
            processingId: 'proc-789',
            message: 'Processing timed out',
            errorCode: 'PROCESSING_TIMEOUT'
        );

        $array = $result->toArray();

        $this->assertFalse($array['success']);
        $this->assertEquals('proc-789', $array['processing_id']);
        $this->assertEquals('Processing timed out', $array['message']);
        $this->assertEquals('PROCESSING_TIMEOUT', $array['error_code']);
    }
}
