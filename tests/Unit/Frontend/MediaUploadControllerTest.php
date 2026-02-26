<?php

declare(strict_types=1);

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaUploadControllerTest extends TestCase
{
    private function timeoutHandlerSource(): string
    {
        $source = file_get_contents(resource_path('js/livewire/media-upload-controller.js'));

        $this->assertIsString($source);

        preg_match('/resetUploadTimeout\(\)\s*\{(.*?)\n\s*\},\n\n\s*registerUploadListeners\(\)/s', $source, $matches);

        $this->assertArrayHasKey(1, $matches, 'Could not locate resetUploadTimeout() implementation.');

        return $matches[1];
    }

    #[Test]
    public function stalled_upload_timeout_does_not_force_a_failure_message(): void
    {
        $timeoutHandler = $this->timeoutHandlerSource();

        $this->assertStringNotContainsString('Upload stalled. Please check your connection and try again.', $timeoutHandler);
        $this->assertStringNotContainsString("this.\$wire.call('handleUploadError'", $timeoutHandler);
    }

    #[Test]
    public function stalled_upload_timeout_does_not_auto_cancel_the_livewire_upload(): void
    {
        $timeoutHandler = $this->timeoutHandlerSource();

        $this->assertStringNotContainsString("component.cancelUpload('mediaFile');", $timeoutHandler);
        $this->assertStringContainsString('this.resetUploadTimeout();', $timeoutHandler);
    }
}
