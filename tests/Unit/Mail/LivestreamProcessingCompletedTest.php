<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Mail\LivestreamProcessingCompleted;
use Tests\TestCase;

class LivestreamProcessingCompletedTest extends TestCase
{
    public function test_constructor_stores_processing_id_and_summary(): void
    {
        $summary = ['sermon_count' => 2, 'total_duration' => '1:23:45'];
        $mail = new LivestreamProcessingCompleted('proc-123', $summary);

        $this->assertEquals('proc-123', $mail->processingId);
        $this->assertEquals($summary, $mail->summary);
    }

    public function test_constructor_defaults_summary_to_empty_array(): void
    {
        $mail = new LivestreamProcessingCompleted('proc-456');

        $this->assertEquals([], $mail->summary);
    }

    public function test_envelope_subject_includes_processing_id(): void
    {
        $mail = new LivestreamProcessingCompleted('proc-789');
        $envelope = $mail->envelope();

        $this->assertStringContainsString('proc-789', $envelope->subject);
        $this->assertStringContainsString('Livestream Processing Completed', $envelope->subject);
    }

    public function test_content_uses_correct_markdown_view(): void
    {
        $mail = new LivestreamProcessingCompleted('proc-test');
        $content = $mail->content();

        $this->assertEquals('emails.livestream-processing-completed', $content->markdown);
    }

    public function test_content_passes_processing_id_and_summary_to_view(): void
    {
        $summary = ['sermon_count' => 3];
        $mail = new LivestreamProcessingCompleted('proc-test', $summary);
        $content = $mail->content();

        $this->assertEquals('proc-test', $content->with['processingId']);
        $this->assertEquals($summary, $content->with['summary']);
    }
}
