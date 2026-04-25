<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonVideoQualityStatus;
use App\Enums\SermonVideoVisibilityOverride;
use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonAssetVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function guest_cannot_access_video_with_force_hide_override(): void
    {
        Storage::fake('public');

        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/hidden-video.mp4',
            'video_visibility_override' => SermonVideoVisibilityOverride::ForceHide,
        ]);

        Storage::disk('public')->put('sermons/hidden-video.mp4', 'fake video content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/video");

        // This is expected to FAIL until fixed (currently it likely redirects or serves)
        // Sentinel expects it to be 403 Forbidden if not authorized.
        $response->assertStatus(403);
    }

    #[Test]
    public function guest_cannot_access_video_with_rejected_quality_status(): void
    {
        Storage::fake('public');
        config(['media-processing.video_quality.enforce_public_visibility' => true]);

        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/rejected-video.mp4',
            'video_quality_status' => SermonVideoQualityStatus::Rejected,
            'video_visibility_override' => SermonVideoVisibilityOverride::Default,
        ]);

        Storage::disk('public')->put('sermons/rejected-video.mp4', 'fake video content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/video");

        $response->assertStatus(403);
    }

    #[Test]
    public function guest_cannot_access_thumbnail_of_hidden_video(): void
    {
        Storage::fake('public');

        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/video.mp4',
            'thumbnail_file_path' => 'thumbnails/thumb.webp',
            'video_visibility_override' => SermonVideoVisibilityOverride::ForceHide,
            'thumbnail_metadata' => [
                'selected_thumbnail_candidate_id' => 'candidate-1',
            ],
        ]);

        Storage::disk('public')->put('thumbnails/thumb.webp', 'fake thumb');

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertStatus(403);
    }

    #[Test]
    public function admin_can_access_hidden_assets(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_admin' => true]);

        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/hidden-video.mp4',
            'thumbnail_file_path' => 'thumbnails/hidden-thumb.webp',
            'video_visibility_override' => SermonVideoVisibilityOverride::ForceHide,
            'thumbnail_metadata' => [
                'selected_thumbnail_candidate_id' => 'candidate-1',
            ],
        ]);

        Storage::disk('public')->put('sermons/hidden-video.mp4', 'fake video');
        Storage::disk('public')->put('thumbnails/hidden-thumb.webp', 'fake thumb');

        $this->actingAs($admin)
            ->get("/christ/sermons/{$sermon->slug}/video")
            ->assertRedirect(); // Serving via redirect to public URL is standard for non-private

        $this->actingAs($admin)
            ->get("/christ/sermons/{$sermon->slug}/thumbnail")
            ->assertRedirect();
    }
}
