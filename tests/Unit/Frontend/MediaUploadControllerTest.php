<?php

declare(strict_types=1);

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaUploadControllerTest extends TestCase
{
    private function controllerSource(): string
    {
        $source = file_get_contents(resource_path('js/livewire/media-upload-controller.js'));

        $this->assertIsString($source);

        return $source;
    }

    public function upload_controller_has_no_self_rearming_stall_timer_or_page_global_cancel_relay(): void
    {
        $source = $this->controllerSource();

        $this->assertStringNotContainsString('STALL_TIMEOUT_MS', $source);
        $this->assertStringNotContainsString('resetUploadTimeout', $source);
        $this->assertStringNotContainsString('media-upload:cancel-upload', $source);
        $this->assertStringNotContainsString('findLivewireComponent', $source);
    }

    #[Test]
    public function upload_finish_handler_guards_against_duplicate_processing_requests(): void
    {
        $source = $this->controllerSource();

        $this->assertStringContainsString('if (this.processingTriggered) {', $source);
        $this->assertStringContainsString('this.processingTriggered = true;', $source);
    }

    #[Test]
    public function upload_start_resets_the_processing_trigger_guard(): void
    {
        $source = $this->controllerSource();

        $this->assertStringContainsString("this.registerListener('livewire-upload-start'", $source);
        $this->assertStringContainsString('this.processingTriggered = false;', $source);
    }
}
