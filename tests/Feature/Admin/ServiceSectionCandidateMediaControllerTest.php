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

    /** The disk phpunit.xml configures as the sermon disk, where candidates live. */
    private const string CANDIDATE_DISK = 'public';

    // ── auth guard ─────────────────────────────────────────────────────────

    #[Test]
    public function audio_preview_redirects_guests_to_login(): void
    {
        $section = ServiceSection::factory()->create([
            'publication_status' => ServiceSectionPublicationStatus::Approved,
            'extracted_audio_path' => 'section-publications/1-abcdef0123456789/audio.mp3',
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

    // ── admins are redirected to storage ───────────────────────────────────

    #[Test]
    public function audio_preview_redirects_an_admin_to_the_candidate_storage_url(): void
    {
        Storage::fake(self::CANDIDATE_DISK);
        $admin = User::factory()->crockenhillAdmin()->create();

        $path = 'section-publications/1-abcdef0123456789/audio.mp3';
        $section = ServiceSection::factory()->create([
            'publication_status' => ServiceSectionPublicationStatus::Approved,
            'extracted_audio_path' => $path,
        ]);

        Storage::disk(self::CANDIDATE_DISK)->put($path, 'audio');

        $response = $this->actingAs($admin)->get(route('admin.services.section-publications.preview-audio', $section));

        $response->assertRedirect(Storage::disk(self::CANDIDATE_DISK)->url($path));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function video_preview_redirects_an_admin_to_the_candidate_storage_url(): void
    {
        Storage::fake(self::CANDIDATE_DISK);
        $admin = User::factory()->crockenhillAdmin()->create();

        $path = 'section-publications/1-abcdef0123456789/video.mp4';
        $section = ServiceSection::factory()->create([
            'publication_status' => ServiceSectionPublicationStatus::Approved,
            'extracted_video_path' => $path,
        ]);

        Storage::disk(self::CANDIDATE_DISK)->put($path, 'video');

        $response = $this->actingAs($admin)->get(route('admin.services.section-publications.preview-video', $section));

        $response->assertRedirect(Storage::disk(self::CANDIDATE_DISK)->url($path));
    }

    /**
     * Legacy rows still naming a `private/` path resolve to the sermon disk,
     * find nothing there, and present as needing re-extraction. That is the
     * intended outcome, not a regression.
     */
    #[Test]
    public function audio_preview_returns_404_for_a_legacy_private_path(): void
    {
        Storage::fake(self::CANDIDATE_DISK);
        Storage::fake('local');
        $admin = User::factory()->crockenhillAdmin()->create();

        $section = ServiceSection::factory()->create([
            'publication_status' => ServiceSectionPublicationStatus::Approved,
            'extracted_audio_path' => 'private/section-publications/1/audio.mp3',
        ]);

        Storage::disk('local')->put('private/section-publications/1/audio.mp3', 'audio');

        $response = $this->actingAs($admin)->get(route('admin.services.section-publications.preview-audio', $section));
        $response->assertStatus(404);
    }

    // ── historic staging is never served over HTTP ─────────────────────────

    /**
     * A reviewer's request holds no staging context, so the refusal has to come
     * from the disk's identity or it would never fire at all.
     */
    #[Test]
    public function audio_preview_returns_404_while_candidates_live_on_the_historic_staging_disk(): void
    {
        Storage::fake(self::CANDIDATE_DISK);
        config(['media-processing.storage.historic_staging_disk' => self::CANDIDATE_DISK]);
        $admin = User::factory()->crockenhillAdmin()->create();

        $path = 'section-publications/1-abcdef0123456789/audio.mp3';
        $section = ServiceSection::factory()->create([
            'publication_status' => ServiceSectionPublicationStatus::Approved,
            'extracted_audio_path' => $path,
        ]);

        Storage::disk(self::CANDIDATE_DISK)->put($path, 'audio');

        $response = $this->actingAs($admin)->get(route('admin.services.section-publications.preview-audio', $section));

        $response->assertStatus(404);
    }

    // ── published sections blocked ─────────────────────────────────────────

    #[Test]
    public function audio_preview_returns_404_for_published_section(): void
    {
        Storage::fake(self::CANDIDATE_DISK);
        $admin = User::factory()->crockenhillAdmin()->create();

        $section = ServiceSection::factory()->create([
            'publication_status' => ServiceSectionPublicationStatus::Published,
            'extracted_audio_path' => 'section-publications/99-abcdef0123456789/audio.mp3',
        ]);

        Storage::disk(self::CANDIDATE_DISK)->put('section-publications/99-abcdef0123456789/audio.mp3', 'audio');

        $response = $this->actingAs($admin)->get(route('admin.services.section-publications.preview-audio', $section));
        $response->assertStatus(404);
    }

    #[Test]
    public function video_preview_returns_404_for_published_section(): void
    {
        Storage::fake(self::CANDIDATE_DISK);
        $admin = User::factory()->crockenhillAdmin()->create();

        $section = ServiceSection::factory()->create([
            'publication_status' => ServiceSectionPublicationStatus::Published,
            'extracted_video_path' => 'section-publications/99-abcdef0123456789/video.mp4',
        ]);

        Storage::disk(self::CANDIDATE_DISK)->put('section-publications/99-abcdef0123456789/video.mp4', 'video');

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
        Storage::fake(self::CANDIDATE_DISK);
        $admin = User::factory()->crockenhillAdmin()->create();

        $section = ServiceSection::factory()->create([
            'publication_status' => ServiceSectionPublicationStatus::Approved,
            'extracted_audio_path' => 'section-publications/1-abcdef0123456789/missing.mp3',
        ]);

        // File is not created on disk

        $response = $this->actingAs($admin)->get(route('admin.services.section-publications.preview-audio', $section));
        $response->assertStatus(404);
    }
}
