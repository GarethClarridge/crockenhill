<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonVideoQualityStatus;
use App\Enums\SermonVideoVisibilityOverride;
use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonAssetSecurityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guest_cannot_access_rejected_video(): void
    {
        Storage::fake('public');
        config(['media-processing.video_quality.enforce_public_visibility' => true]);

        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/video.mp4',
            'video_quality_status' => SermonVideoQualityStatus::Rejected,
        ]);
        Storage::disk('public')->put('sermons/video.mp4', 'fake video');

        $response = $this->get("/christ/sermons/{$sermon->slug}/video");

        // Currently it might redirect (302) or return 200/302 depending on the bug.
        // It should probably return 404 or 403 if restricted.
        // The policy says it shouldn't be exposed.
        $response->assertStatus(404);
    }

    #[Test]
    public function admin_can_access_rejected_video(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();

        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/video.mp4',
            'video_quality_status' => SermonVideoQualityStatus::Rejected,
        ]);
        Storage::disk('public')->put('sermons/video.mp4', 'fake video');

        $response = $this->actingAs($admin)->get("/christ/sermons/{$sermon->slug}/video");

        // Admins are allowed, so they should be redirected to the public URL for public assets
        $response->assertRedirect();
        $this->assertStringContainsString('sermons/video.mp4', $response->headers->get('Location'));
    }

    #[Test]
    public function guest_cannot_access_force_hidden_video(): void
    {
        Storage::fake('public');
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/video.mp4',
            'video_visibility_override' => SermonVideoVisibilityOverride::ForceHide,
        ]);
        Storage::disk('public')->put('sermons/video.mp4', 'fake video');

        $response = $this->get("/christ/sermons/{$sermon->slug}/video");

        $response->assertStatus(404);
    }

    #[Test]
    public function guest_cannot_access_thumbnail_when_video_is_rejected(): void
    {
        Storage::fake('public');
        config(['media-processing.video_quality.enforce_public_visibility' => true]);

        $sermon = Sermon::factory()->create([
            'thumbnail_file_path' => 'thumbnails/thumb.webp',
            'video_file_path' => 'sermons/video.mp4',
            'video_quality_status' => SermonVideoQualityStatus::Rejected,
            'thumbnail_metadata' => [
                'video_duration' => 100, // Indicates it's a video-generated thumbnail
            ],
        ]);
        Storage::disk('public')->put('thumbnails/thumb.webp', 'fake thumb');

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertStatus(404);
    }

    #[Test]
    public function admin_can_access_restricted_thumbnail(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();

        $sermon = Sermon::factory()->create([
            'thumbnail_file_path' => 'thumbnails/thumb.webp',
            'video_file_path' => 'sermons/video.mp4',
            'video_quality_status' => SermonVideoQualityStatus::Rejected,
            'thumbnail_metadata' => [
                'video_duration' => 100,
            ],
        ]);
        Storage::disk('public')->put('thumbnails/thumb.webp', 'fake thumb');

        $response = $this->actingAs($admin)->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertRedirect();
        $this->assertStringContainsString('thumbnails/thumb.webp', $response->headers->get('Location'));
    }

    /**
     * No asset route streams from the local disk any more — they authorise and
     * then redirect to the configured disk. A legacy `private/` path is therefore
     * unreachable for everyone, admins included, and that is deliberate: it is
     * what proves the `response()->file()` fallback has not crept back in.
     */
    #[Test]
    public function a_legacy_private_path_is_served_to_nobody(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $admin = User::factory()->admin()->create();

        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'private/sermons/audio.mp3',
            'video_file_path' => 'private/sermons/video.mp4',
        ]);
        Storage::disk('local')->put('private/sermons/audio.mp3', 'fake audio');
        Storage::disk('local')->put('private/sermons/video.mp4', 'fake video');

        $this->get("/christ/sermons/{$sermon->slug}/audio")->assertStatus(404);
        $this->get("/christ/sermons/{$sermon->slug}/video")->assertStatus(404);
        $this->actingAs($admin)->get("/christ/sermons/{$sermon->slug}/audio")->assertStatus(404);
        $this->actingAs($admin)->get("/christ/sermons/{$sermon->slug}/video")->assertStatus(404);
    }

    #[Test]
    public function admin_can_access_ordinary_sermon_assets(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();

        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'sermons/audio.mp3',
            'video_file_path' => 'sermons/video.mp4',
        ]);
        Storage::disk('public')->put('sermons/audio.mp3', 'fake audio');
        Storage::disk('public')->put('sermons/video.mp4', 'fake video');

        $this->actingAs($admin)->get("/christ/sermons/{$sermon->slug}/audio")->assertRedirect();
        $this->actingAs($admin)->get("/christ/sermons/{$sermon->slug}/video")->assertRedirect();
    }
}
