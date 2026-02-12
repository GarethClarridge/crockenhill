<?php

namespace Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConfigValidationTest extends TestCase
{
    #[Test]
    public function it_has_required_app_config(): void
    {
        $this->assertNotEmpty(config('app.url'), 'APP_URL is not set');
        $this->assertStringStartsWith('http', config('app.url'), 'APP_URL must start with http/https');
    }

    #[Test]
    public function it_has_openai_config(): void
    {
        // Testing that the config exists, even if empty in test env
        $this->assertArrayHasKey('api_key', config('openai') ?? [], 'openai.api_key config is missing');
    }

    #[Test]
    public function it_has_required_storage_disks(): void
    {
        $disks = config('filesystems.disks');
        $this->assertArrayHasKey('public', $disks);
        $this->assertArrayHasKey('local', $disks);
        $this->assertArrayHasKey('do_spaces', $disks);
    }

    #[Test]
    public function it_has_media_processing_config(): void
    {
        $this->assertNotEmpty(config('media-processing.types.audio.queue'), 'media-processing.types.audio.queue is not set');
    }
}
