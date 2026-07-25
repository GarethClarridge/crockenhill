<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonContentType;
use App\Models\Sermon;
use App\Models\User;
use App\Services\Sermon\SermonStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonVideoServingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        Config::set('media-processing.storage.sermon_disk', 'public');
    }

    #[Test]
    public function can_serve_public_video_by_redirect(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-video',
            'video_file_path' => 'sermons/test.mp4',
        ]);

        Storage::disk('public')->put('sermons/test.mp4', 'fake video content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/video");

        $expectedUrl = app(SermonStorageService::class)->getVideoDeliveryUrl($sermon);
        $response->assertRedirect($expectedUrl);
    }

    #[Test]
    public function a_legacy_private_video_path_is_no_longer_streamed(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-private-video',
            'video_file_path' => 'private/sermons/test.mp4',
        ]);

        Storage::disk('local')->put('private/sermons/test.mp4', 'fake private video content');

        $admin = User::factory()->crockenhillAdmin()->create();
        $response = $this->actingAs($admin)->get("/christ/sermons/{$sermon->slug}/video");

        // The route resolves against the sermon disk only; nothing streams from
        // the local disk any more, so a legacy path is unreachable even for admins.
        $response->assertStatus(404);
    }

    #[Test]
    public function returns_404_when_video_path_is_missing(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'no-video',
            'video_file_path' => null,
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/video");

        $response->assertStatus(404);
    }

    #[Test]
    public function returns_404_when_video_file_missing_on_disk(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'missing-file',
            'video_file_path' => 'sermons/nonexistent.mp4',
        ]);

        // We don't put the file on disk

        $response = $this->get("/christ/sermons/{$sermon->slug}/video");

        $response->assertStatus(404);
    }

    #[Test]
    public function prevents_path_traversal_on_video_requests(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'malicious-path',
            'video_file_path' => '../secret.mp4',
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/video");

        $response->assertStatus(404);
    }

    #[Test]
    public function guest_cannot_access_childrens_talk_video_when_not_public(): void
    {
        Config::set('church.sermons.childrens_talks.public', false);

        $sermon = Sermon::factory()->create([
            'slug' => 'childrens-talk',
            'content_type' => SermonContentType::ChildrensTalk,
            'video_file_path' => 'sermons/kids.mp4',
        ]);

        Storage::disk('public')->put('sermons/kids.mp4', 'fake content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/video");

        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function verified_user_can_access_childrens_talk_video_when_not_public(): void
    {
        Config::set('church.sermons.childrens_talks.public', false);
        $user = User::factory()->create(['email_verified_at' => now()]);

        $sermon = Sermon::factory()->create([
            'slug' => 'childrens-talk',
            'content_type' => SermonContentType::ChildrensTalk,
            'video_file_path' => 'sermons/kids.mp4',
        ]);

        Storage::disk('public')->put('sermons/kids.mp4', 'fake content');

        $response = $this->actingAs($user)->get("/christ/sermons/{$sermon->slug}/video");

        $expectedUrl = app(SermonStorageService::class)->getVideoDeliveryUrl($sermon);
        $response->assertRedirect($expectedUrl);
    }

    #[Test]
    public function unverified_user_cannot_access_childrens_talk_video_when_not_public(): void
    {
        Config::set('church.sermons.childrens_talks.public', false);
        $user = User::factory()->create(['email_verified_at' => null]);

        $sermon = Sermon::factory()->create([
            'slug' => 'childrens-talk',
            'content_type' => SermonContentType::ChildrensTalk,
            'video_file_path' => 'sermons/kids.mp4',
        ]);

        Storage::disk('public')->put('sermons/kids.mp4', 'fake content');

        $response = $this->actingAs($user)->get("/christ/sermons/{$sermon->slug}/video");

        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function guest_can_access_childrens_talk_video_when_public(): void
    {
        Config::set('church.sermons.childrens_talks.public', true);

        $sermon = Sermon::factory()->create([
            'slug' => 'childrens-talk',
            'content_type' => SermonContentType::ChildrensTalk,
            'video_file_path' => 'sermons/kids.mp4',
        ]);

        Storage::disk('public')->put('sermons/kids.mp4', 'fake content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/video");

        $expectedUrl = app(SermonStorageService::class)->getVideoDeliveryUrl($sermon);
        $response->assertRedirect($expectedUrl);
    }
}
