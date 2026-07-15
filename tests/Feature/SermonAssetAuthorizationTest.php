<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonContentType;
use App\Jobs\MoveSermonToPrivateStorage;
use App\Models\Sermon;
use App\Models\User;
use App\Services\Sermon\SermonStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonAssetAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        Queue::fake([MoveSermonToPrivateStorage::class]);
    }

    // ── Unauthorized Guest Access ──────────────────────────────────────────

    #[Test]
    public function guest_is_redirected_when_accessing_private_childrens_talk_audio(): void
    {
        Config::set('church.sermons.childrens_talks.public', false);

        $talk = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'sermons/talk-audio.mp3',
        ]);
        Storage::disk('public')->put('sermons/talk-audio.mp3', 'fake audio');

        $response = $this->get(route('sermons.audio', $talk));

        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function guest_is_redirected_when_accessing_private_childrens_talk_video(): void
    {
        Config::set('church.sermons.childrens_talks.public', false);

        $talk = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'video_file_path' => 'sermons/talk-video.mp4',
        ]);
        Storage::disk('public')->put('sermons/talk-video.mp4', 'fake video');

        $response = $this->get(route('sermons.video', $talk));

        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function guest_is_redirected_when_accessing_private_childrens_talk_thumbnail(): void
    {
        Config::set('church.sermons.childrens_talks.public', false);

        $talk = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'thumbnail_file_path' => 'thumbnails/talk-thumb.webp',
        ]);
        Storage::disk('public')->put('thumbnails/talk-thumb.webp', 'fake thumb');

        $response = $this->get(route('sermons.thumbnail', $talk));

        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function guest_is_redirected_when_accessing_private_childrens_talk_card_thumbnail(): void
    {
        Config::set('church.sermons.childrens_talks.public', false);

        $talk = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'thumbnail_metadata' => ['plain_thumbnail_path' => 'thumbnails/talk-card.jpg'],
        ]);
        Storage::disk('public')->put('thumbnails/talk-card.jpg', 'fake card');

        $response = $this->get(route('sermons.thumbnail.card', $talk));

        $response->assertRedirect(route('login'));
    }

    // ── Authorized Guest Access (When Public) ──────────────────────────────

    #[Test]
    public function guest_can_access_childrens_talk_audio_when_public(): void
    {
        Config::set('church.sermons.childrens_talks.public', true);

        $talk = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'sermons/public-talk-audio.mp3',
        ]);
        Storage::disk('public')->put('sermons/public-talk-audio.mp3', 'fake audio');

        $response = $this->get(route('sermons.audio', $talk));

        $response->assertRedirect(app(SermonStorageService::class)->getPublicUrl($talk));
    }

    #[Test]
    public function guest_can_access_childrens_talk_thumbnail_when_public(): void
    {
        Config::set('church.sermons.childrens_talks.public', true);

        $talk = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'thumbnail_file_path' => 'thumbnails/public-talk-thumb.webp',
        ]);
        Storage::disk('public')->put('thumbnails/public-talk-thumb.webp', 'fake thumb');

        $response = $this->get(route('sermons.thumbnail', $talk));

        $response->assertRedirect(app(SermonStorageService::class)->getThumbnailUrl($talk));
    }

    #[Test]
    public function guest_can_access_childrens_talk_video_when_public(): void
    {
        Config::set('church.sermons.childrens_talks.public', true);

        $talk = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'video_file_path' => 'sermons/public-talk-video.mp4',
        ]);
        Storage::disk('public')->put('sermons/public-talk-video.mp4', 'fake video');

        $response = $this->get(route('sermons.video', $talk));

        $response->assertRedirect(app(SermonStorageService::class)->getVideoUrl($talk));
    }

    // ── Authenticated Access (Even When Not Public) ─────────────────────────

    #[Test]
    public function authenticated_user_can_access_private_childrens_talk_audio(): void
    {
        Config::set('church.sermons.childrens_talks.public', false);
        $user = User::factory()->create();

        $talk = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'sermons/user-talk-audio.mp3',
        ]);
        Storage::disk('public')->put('sermons/user-talk-audio.mp3', 'fake audio');

        $response = $this->actingAs($user)->get(route('sermons.audio', $talk));

        $response->assertRedirect(app(SermonStorageService::class)->getPublicUrl($talk));
    }

    #[Test]
    public function authenticated_user_can_access_private_childrens_talk_video(): void
    {
        Config::set('church.sermons.childrens_talks.public', false);
        $user = User::factory()->create();

        $talk = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'video_file_path' => 'sermons/user-talk-video.mp4',
        ]);
        Storage::disk('public')->put('sermons/user-talk-video.mp4', 'fake video');

        $response = $this->actingAs($user)->get(route('sermons.video', $talk));

        $response->assertRedirect(app(SermonStorageService::class)->getVideoUrl($talk));
    }

    // ── Regular Sermon Access ──────────────────────────────────────────────

    #[Test]
    public function guest_can_always_access_regular_sermon_audio(): void
    {
        // Public talk setting shouldn't affect regular sermons
        Config::set('church.sermons.childrens_talks.public', false);

        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::Sermon,
            'audio_file_path' => 'sermons/regular-audio.mp3',
        ]);
        Storage::disk('public')->put('sermons/regular-audio.mp3', 'fake audio');

        $response = $this->get(route('sermons.audio', $sermon));

        $response->assertRedirect(app(SermonStorageService::class)->getPublicUrl($sermon));
    }
}
