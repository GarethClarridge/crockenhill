<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Mail\DiskSpaceWarning;
use Tests\TestCase;

class DiskSpaceWarningTest extends TestCase
{
    public function test_constructor_stores_processing_id(): void
    {
        $mail = new DiskSpaceWarning('proc-123');

        $this->assertEquals('proc-123', $mail->processingId);
    }

    public function test_envelope_has_correct_static_subject(): void
    {
        $mail = new DiskSpaceWarning('proc-456');
        $envelope = $mail->envelope();

        $this->assertEquals('Critical: Disk Space Error - Livestream Processing', $envelope->subject);
    }

    public function test_content_uses_correct_markdown_view(): void
    {
        $mail = new DiskSpaceWarning('proc-789');
        $content = $mail->content();

        $this->assertEquals('emails.disk-space-warning', $content->markdown);
    }

    public function test_content_passes_processing_id_to_view(): void
    {
        $processingId = 'proc-test-id';
        $mail = new DiskSpaceWarning($processingId);
        $content = $mail->content();

        $this->assertEquals($processingId, $content->with['processingId']);
    }
}
