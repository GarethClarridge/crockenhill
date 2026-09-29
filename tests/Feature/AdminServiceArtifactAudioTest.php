<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MediaProcessingLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminServiceArtifactAudioTest extends TestCase
{
    use RefreshDatabase;

    private const string PATH = 'service-audio/2026-03-22/morning-x.mp3';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('service_artifacts');
        config(['media-processing.storage.service_artifact_disk' => 'service_artifacts']);
    }

    #[Test]
    public function an_admin_can_play_the_runs_archived_service_audio(): void
    {
        $run = $this->runWithAudio();
        Storage::disk('service_artifacts')->put(self::PATH, 'compressed audio');

        $response = $this->actingAs($this->admin())->get(route('admin.recordings.service-audio', $run));

        $response->assertOk();
        $response->assertHeader('Accept-Ranges', 'bytes');
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('compressed audio', file_get_contents($response->baseResponse->getFile()->getPathname()));
    }

    #[Test]
    public function the_audio_is_not_served_to_anyone_but_an_admin(): void
    {
        $run = $this->runWithAudio();
        Storage::disk('service_artifacts')->put(self::PATH, 'compressed audio');

        $this->get(route('admin.recordings.service-audio', $run))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
            ->get(route('admin.recordings.service-audio', $run))
            ->assertForbidden();
    }

    #[Test]
    public function a_run_without_archived_audio_or_with_an_unsafe_path_is_not_found(): void
    {
        $withoutAudio = MediaProcessingLog::factory()->livestream()->completed()->create(['processing_metadata' => []]);
        $unsafe = MediaProcessingLog::factory()->livestream()->completed()->create(['processing_metadata' => [
            'service_artifacts' => [['kind' => 'audio', 'disk' => 'service_artifacts', 'path' => 'service-audio/../../.env']],
        ]]);
        $missing = $this->runWithAudio();

        foreach ([$withoutAudio, $unsafe, $missing] as $run) {
            $this->actingAs($this->admin())->get(route('admin.recordings.service-audio', $run))->assertNotFound();
        }
    }

    private function runWithAudio(): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->livestream()->completed()->create(['processing_metadata' => [
            'service_artifacts' => [['kind' => 'audio', 'disk' => 'service_artifacts', 'path' => self::PATH]],
        ]]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
    }
}
