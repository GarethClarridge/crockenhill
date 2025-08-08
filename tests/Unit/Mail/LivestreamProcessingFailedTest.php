<?php

namespace Tests\Unit\Mail;

use App\Mail\LivestreamProcessingFailed;
use Exception;
use Tests\TestCase;

class LivestreamProcessingFailedTest extends TestCase
{
    public function test_can_create_with_exception_object()
    {
        $processingId = 'test-processing-id-123';
        $exception = new Exception('Test error message', 500);
        $step = 'audio_analysis';

        $mail = new LivestreamProcessingFailed($processingId, $exception, $step);

        $this->assertEquals($processingId, $mail->processingId);
        $this->assertEquals($exception, $mail->exception);
        $this->assertEquals($step, $mail->step);
    }

    public function test_can_create_with_string_message()
    {
        $processingId = 'test-processing-id-456';
        $errorMessage = 'Test error message as string';
        $step = 'segmentation';

        $mail = new LivestreamProcessingFailed($processingId, $errorMessage, $step);

        $this->assertEquals($processingId, $mail->processingId);
        $this->assertEquals($errorMessage, $mail->exception);
        $this->assertEquals($step, $mail->step);
    }

    public function test_envelope_contains_processing_id()
    {
        $processingId = 'test-processing-id-789';
        $exception = new Exception('Test error');
        
        $mail = new LivestreamProcessingFailed($processingId, $exception);
        $envelope = $mail->envelope();

        $this->assertStringContainsString($processingId, $envelope->subject);
        $this->assertStringContainsString('Livestream Processing Failed', $envelope->subject);
    }

    public function test_content_with_exception_object_includes_detailed_info()
    {
        $processingId = 'test-processing-id-exception';
        $exception = new Exception('Detailed test error message');
        $step = 'sermon_extraction';

        $mail = new LivestreamProcessingFailed($processingId, $exception, $step);
        $content = $mail->content();

        $this->assertEquals('emails.livestream-processing-failed', $content->markdown);
        
        $data = $content->with;
        $this->assertEquals($processingId, $data['processingId']);
        $this->assertEquals($step, $data['step']);
        $this->assertEquals('Detailed test error message', $data['errorMessage']);
        $this->assertNotEmpty($data['stackTrace']);
        $this->assertNotEmpty($data['file']);
        $this->assertNotEmpty($data['line']);
        $this->assertNotEquals('Unknown', $data['file']);
        $this->assertNotEquals('Unknown', $data['line']);
    }

    public function test_content_with_string_message_includes_fallback_info()
    {
        $processingId = 'test-processing-id-string';
        $errorMessage = 'Simple error message string';
        $step = 'cleanup';

        $mail = new LivestreamProcessingFailed($processingId, $errorMessage, $step);
        $content = $mail->content();

        $this->assertEquals('emails.livestream-processing-failed', $content->markdown);
        
        $data = $content->with;
        $this->assertEquals($processingId, $data['processingId']);
        $this->assertEquals($step, $data['step']);
        $this->assertEquals($errorMessage, $data['errorMessage']);
        $this->assertEquals('Stack trace not available (error provided as string)', $data['stackTrace']);
        $this->assertEquals('Unknown', $data['file']);
        $this->assertEquals('Unknown', $data['line']);
    }

    public function test_defaults_to_unknown_step()
    {
        $processingId = 'test-processing-id-default';
        $exception = new Exception('Test error');

        $mail = new LivestreamProcessingFailed($processingId, $exception);

        $this->assertEquals('unknown', $mail->step);
    }
}