<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\ServiceSectionPublicationStatus;
use App\Models\ServiceSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceSectionCandidateMediaControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── auth guard ─────────────────────────────────────────────────────────

    #[Test]
    public function audio_preview_redirects_guests_to_login(): void
    {
        $section = ServiceSection::factory()->create([
            'publication_status' => ServiceSectionPublicationStatus::Approved,
            'extracted_audio_path' => 'private/section-publications/1/audio.mp3',
        ]);

        $response = $this->get(route('admin.services.section-publications.preview-audio', $section));
        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function audio_preview_returns_403_for_non_admin(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $section = ServiceSection::factory()->create([
            'publication_status' => ServiceSectionPublicationStatus::Approved,
        ]);

        $response = $this->actingAs($user)->get(route('admin.services.section-publications.preview-audio', $section));
        $response->assertStatus(403);
    }

    // ── published sections blocked ─────────────────────────────────────────

    #[Test]
    public function audio_preview_returns_404_for_published_section(): void
    {
        Storage::fake('local');
        $admin = User::factory()->crockenhillAdmin()->create();

        $section = ServiceSection::factory()->create([
            'publication_status' => ServiceSectionPublicationStatus::Published,
            'extracted_audio_path' => 'private/section-publications/99/audio.mp3',
        ]);

        Storage::disk('local')->put('private/section-publications/99/audio.mp3', 'audio');

        $response = $this->actingAs($admin)->get(route('admin.services.section-publications.preview-audio', $section));
        $response->assertStatus(404);
    }

    #[Test]
    public function video_preview_returns_404_for_published_section(): void
    {
        Storage::fake('local');
        $admin = User::factory()->crockenhillAdmin()->create();

        $section = ServiceSection::factory()->create([
            'publication_status' => ServiceSectionPublicationStatus::Published,
            'extracted_video_path' => 'private/section-publications/99/video.mp4',
        ]);

        Storage::disk('local')->put('private/section-publications/99/video.mp4', 'video');

        $response = $this->actingAs($admin)->get(route('admin.services.section-publications.preview-video', $section));
        $response->assertStatus(404);
    }

    // ── path traversal ─────────────────────────────────────────────────────

    #[Test]
    public function audio_preview_returns_404_for_path_traversal_attempt(): void
    {
        $admin = User::factory()->crockenhillAdmin()->create();

        $section = ServiceSection::factory()->create([
            'publication_status' => ServiceSectionPublicationStatus::Approved,
            'extracted_audio_path' => '../../etc/passwd',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.services.section-publications.preview-audio', $section));
        $response->assertStatus(404);
    }

    // ── missing file ────────────────────────────────────────────────────────

    #[Test]
    public function audio_preview_returns_404_when_file_does_not_exist(): void
    {
        Storage::fake('local');
        $admin = User::factory()->crockenhillAdmin()->create();

        $section = ServiceSection::factory()->create([
            'publication_status' => ServiceSectionPublicationStatus::Approved,
            'extracted_audio_path' => 'private/section-publications/1/missing.mp3',
        ]);

        // File is not created on disk

        $response = $this->actingAs($admin)->get(route('admin.services.section-publications.preview-audio', $section));
        $response->assertStatus(404);
    }
}
