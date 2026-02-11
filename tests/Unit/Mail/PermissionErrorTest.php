<?php

namespace Tests\Unit\Mail;

use App\Mail\PermissionError;
use Tests\TestCase;

class PermissionErrorTest extends TestCase
{
    public function test_constructor_stores_processing_id_and_operation(): void
    {
        $mail = new PermissionError('proc-123', 'write_video_file');

        $this->assertEquals('proc-123', $mail->processingId);
        $this->assertEquals('write_video_file', $mail->operation);
    }

    public function test_envelope_has_correct_static_subject(): void
    {
        $mail = new PermissionError('proc-456', 'read_audio');
        $envelope = $mail->envelope();

        $this->assertEquals('File Permission Error - Livestream Processing', $envelope->subject);
    }

    public function test_content_uses_correct_markdown_view(): void
    {
        $mail = new PermissionError('proc-789', 'delete_temp');
        $content = $mail->content();

        $this->assertEquals('emails.permission-error', $content->markdown);
    }

    public function test_content_passes_processing_id_and_operation_to_view(): void
    {
        $mail = new PermissionError('proc-test', 'create_directory');
        $content = $mail->content();

        $this->assertEquals('proc-test', $content->with['processingId']);
        $this->assertEquals('create_directory', $content->with['operation']);
    }
}
