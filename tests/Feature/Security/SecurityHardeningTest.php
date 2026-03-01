<?php

namespace Tests\Feature\Security;

use App\Models\Sermon;
use App\Models\User;
use App\Services\SermonStorageService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @test
     */
    public function sermon_transcript_accessor_prevents_path_traversal()
    {
        $sermon = Sermon::factory()->create([
            'transcript_file_path' => '../../etc/passwd',
        ]);

        $this->assertNull($sermon->transcript);
    }

    /**
     * @test
     */
    public function sermon_storage_service_prevents_path_traversal_in_audio_path()
    {
        $sermon = Sermon::factory()->create([
            'audio_file_path' => '../../etc/passwd',
        ]);

        $service = app(SermonStorageService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Path traversal detected');

        $service->getSermonFileInfo($sermon);
    }

    /**
     * @test
     */
    public function organization_schema_json_ld_is_properly_escaped()
    {
        // Set a config value that contains potentially dangerous characters
        Config::set('organization.name', 'Church </script><script>alert("xss")</script>');

        $rendered = view('components.schema.organization')->render();

        // Basic check: we don't want raw tags
        $this->assertStringNotContainsString('</script><script>', $rendered);
        // We want escaped characters. Note that JSON_UNESCAPED_SLASHES is used,
        // so we expect / instead of \/
        $this->assertStringContainsString('\u003C/script\u003E', $rendered);
    }

    /**
     * @test
     */
    public function bootstrap_app_uses_config_for_trusted_proxies()
    {
        // Verify the config exists and matches what we expect from env
        $this->assertEquals(env('TRUSTED_PROXIES'), config('app.trusted_proxies'));
    }
}
