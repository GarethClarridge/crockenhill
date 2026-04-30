<?php

declare(strict_types=1);

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
    public function testing_environment_uses_safe_openai_defaults(): void
    {
        $this->assertSame('testy-test-key', config('openai.api_key'));
        $this->assertSame('http://127.0.0.1:1/v1', config('openai.base_uri'));
        $this->assertSame('testy-test-key', config('media-processing.transcription.openai_api_key'));
        $this->assertSame('testy-test-key', config('media-processing.analysis.openai_api_key'));
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
        $this->assertNotEmpty(config('media-processing.queues.livestream_audio'), 'media-processing.queues.livestream_audio is not set');
    }

    #[Test]
    public function it_configures_queue_retry_after_for_long_running_jobs(): void
    {
        $redisRetryAfter = (int) config('queue.connections.redis.retry_after', 0);
        $databaseRetryAfter = (int) config('queue.connections.database.retry_after', 0);

        $this->assertGreaterThanOrEqual(1800, $redisRetryAfter, 'Redis retry_after must exceed transcription timeout');
        $this->assertGreaterThanOrEqual(1800, $databaseRetryAfter, 'Database retry_after must exceed transcription timeout');
    }

    #[Test]
    public function it_defaults_session_cookies_to_same_site_lax(): void
    {
        $this->assertSame('lax', config('session.same_site'));
    }

    #[Test]
    public function transcription_service_resolves_from_canonical_config_key(): void
    {
        $this->assertNotNull(
            config('media-processing.transcription.service'),
            'media-processing.transcription.service must resolve to a value'
        );

        $this->assertNull(
            config('media-processing.transcription.service_type'),
            'The deprecated service_type key must not exist in config'
        );
    }

    #[Test]
    public function trusted_proxies_config_key_exists_as_canonical_reference(): void
    {
        // The bootstrap reads env('TRUSTED_PROXIES') directly (config() is unsafe at that stage).
        // This key exists in config/app.php as the canonical documentation of the env variable.
        $this->assertArrayHasKey(
            'trusted_proxies',
            config('app'),
            'app.trusted_proxies config key must exist'
        );
    }

    #[Test]
    public function storage_disks_resolve_from_canonical_env_keys(): void
    {
        // Both keys must resolve to a non-empty string disk name.
        $this->assertNotEmpty(
            config('media-processing.storage.sermon_disk'),
            'media-processing.storage.sermon_disk must resolve to a disk name'
        );

        $this->assertNotEmpty(
            config('media-processing.storage.transcript_disk'),
            'media-processing.storage.transcript_disk must resolve to a disk name'
        );
    }
}
