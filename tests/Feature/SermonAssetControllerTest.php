<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonContentType;
use App\Jobs\MoveSermonToPrivateStorage;
use App\Models\Sermon;
use App\Models\User;
use App\Services\Media\Audio\SermonTranscriptReader;
use App\Services\Sermon\SermonStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonAssetControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([MoveSermonToPrivateStorage::class]);
    }

    #[Test]
    public function it_serves_audio_file_for_local_storage(): void
    {
        Storage::fake('public');

        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'audio_file_path' => 'sermons/test-audio.mp3',
        ]);

        // Create the fake audio file
        Storage::disk('public')->put('sermons/test-audio.mp3', 'fake audio content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/audio");

        $response->assertRedirect(app(SermonStorageService::class)->getPublicUrl($sermon));
    }

    #[Test]
    public function authenticated_user_can_access_non_public_childrens_talk_thumbnail(): void
    {
        Storage::fake('public');
        config(['church.sermons.childrens_talks.public' => false]);

        $user = User::factory()->create();
        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'thumbnail_file_path' => 'thumbnails/childrens-talk.webp',
        ]);

        Storage::disk('public')->put('thumbnails/childrens-talk.webp', 'fake thumb');

        $response = $this->actingAs($user)->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertRedirect();
        $this->assertStringContainsString('childrens-talk.webp', $response->headers->get('Location'));
    }

    #[Test]
    public function it_returns_404_when_audio_file_does_not_exist(): void
    {
        Storage::fake('public');

        $sermon = Sermon::factory()->create([
            'slug' => 'missing-audio-sermon',
            'audio_file_path' => 'sermons/missing.mp3',
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/audio");

        $response->assertStatus(404);
    }

    #[Test]
    public function it_serves_thumbnail_for_a_sermon(): void
    {
        Storage::fake('public');

        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'thumbnail_file_path' => 'thumbnails/test-thumb.webp',
        ]);

        // Create the fake thumbnail file
        Storage::disk('public')->put('thumbnails/test-thumb.webp', 'fake webp content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertRedirect(app(SermonStorageService::class)->getThumbnailUrl($sermon));
    }

    #[Test]
    public function it_serves_video_for_a_sermon(): void
    {
        Storage::fake('public');

        $sermon = Sermon::factory()->create([
            'slug' => 'test-video-sermon',
            'video_file_path' => 'sermons/test-video.mp4',
        ]);

        Storage::disk('public')->put('sermons/test-video.mp4', 'fake video content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/video");

        $response->assertRedirect(app(SermonStorageService::class)->getVideoUrl($sermon));
    }

    #[Test]
    public function it_returns_404_when_thumbnail_file_does_not_exist(): void
    {
        Storage::fake('public');

        $sermon = Sermon::factory()->create([
            'slug' => 'missing-thumbnail-sermon',
            'thumbnail_file_path' => 'thumbnails/missing.webp',
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertStatus(404);
    }

    #[Test]
    public function it_sets_correct_cache_headers_for_thumbnail(): void
    {
        Storage::fake('public');

        $sermon = Sermon::factory()->create([
            'slug' => 'cached-thumbnail-sermon',
            'thumbnail_file_path' => 'thumbnails/cached.jpg',
        ]);

        Storage::disk('public')->put('thumbnails/cached.jpg', 'fake jpg content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertRedirect(app(SermonStorageService::class)->getThumbnailUrl($sermon));
    }

    #[Test]
    public function it_detects_correct_content_type_for_jpg(): void
    {
        Storage::fake('public');

        $sermon = Sermon::factory()->create([
            'slug' => 'jpg-sermon',
            'thumbnail_file_path' => 'thumbnails/image.jpg',
        ]);

        Storage::disk('public')->put('thumbnails/image.jpg', 'fake jpg');

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertRedirect(app(SermonStorageService::class)->getThumbnailUrl($sermon));
    }

    #[Test]
    public function it_detects_correct_content_type_for_png(): void
    {
        Storage::fake('public');

        $sermon = Sermon::factory()->create([
            'slug' => 'png-sermon',
            'thumbnail_file_path' => 'thumbnails/image.png',
        ]);

        Storage::disk('public')->put('thumbnails/image.png', 'fake png');

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertRedirect(app(SermonStorageService::class)->getThumbnailUrl($sermon));
    }

    #[Test]
    public function it_serves_card_thumbnail_successfully(): void
    {
        Storage::fake('public');
        config(['thumbnail-generation.storage.disk' => 'public']);

        $sermon = Sermon::factory()->create([
            'slug' => 'card-test-sermon',
            'thumbnail_metadata' => [
                'card_thumbnail_path' => 'thumbnails/card.webp',
            ],
        ]);

        Storage::disk('public')->put('thumbnails/card.webp', 'fake webp content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail/card");

        $response->assertRedirect(app(SermonStorageService::class)->getCardThumbnailUrl($sermon));
    }

    #[Test]
    public function it_returns_404_when_card_thumbnail_is_missing(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'no-card-test-sermon',
            'thumbnail_metadata' => null,
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail/card");

        $response->assertStatus(404);
    }

    #[Test]
    public function it_returns_404_when_card_thumbnail_file_missing_on_disk(): void
    {
        Storage::fake('public');
        config(['thumbnail-generation.storage.disk' => 'public']);

        $sermon = Sermon::factory()->create([
            'slug' => 'missing-file-card-sermon',
            'thumbnail_metadata' => [
                'card_thumbnail_path' => 'thumbnails/missing-on-disk.webp',
            ],
        ]);

        // We DO NOT put the file on the fake disk

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail/card");

        $response->assertStatus(404);
    }

    #[Test]
    public function it_throttles_audio_download_requests(): void
    {
        Storage::fake('public');

        $sermon = Sermon::factory()->create([
            'slug' => 'throttled-audio',
            'audio_file_path' => 'sermons/throttled.mp3',
        ]);

        Storage::disk('public')->put('sermons/throttled.mp3', 'fake audio content');

        // Clear limiter state for this test (using an empty key to match the IP-only case or the general pattern)
        RateLimiter::clear('media-audio|127.0.0.1');

        // Limit is 10 per minute for audio
        for ($i = 0; $i < 10; $i++) {
            $this->get("/christ/sermons/{$sermon->slug}/audio")
                ->assertStatus(302);
        }

        $this->get("/christ/sermons/{$sermon->slug}/audio")
            ->assertStatus(429);
    }

    #[Test]
    public function it_throttles_thumbnail_requests_independently(): void
    {
        Storage::fake('public');

        $sermon = Sermon::factory()->create([
            'slug' => 'throttled-thumb',
            'thumbnail_file_path' => 'thumbnails/throttled.webp',
        ]);

        Storage::disk('public')->put('thumbnails/throttled.webp', 'fake thumb');

        RateLimiter::clear('media-thumbnail|127.0.0.1');

        // Limit is 120 per minute for thumbnails
        for ($i = 0; $i < 120; $i++) {
            $this->get("/christ/sermons/{$sermon->slug}/thumbnail")
                ->assertStatus(302);
        }

        $this->get("/christ/sermons/{$sermon->slug}/thumbnail")
            ->assertStatus(429);
    }

    #[Test]
    public function guest_is_redirected_when_accessing_non_public_childrens_talk_audio(): void
    {
        config(['church.sermons.childrens_talks.public' => false]);

        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'sermons/childrens-talk.mp3',
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/audio");

        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function guest_is_redirected_when_accessing_non_public_childrens_talk_thumbnail(): void
    {
        config(['church.sermons.childrens_talks.public' => false]);

        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'thumbnail_file_path' => 'thumbnails/childrens-talk.webp',
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function guest_is_redirected_when_accessing_non_public_childrens_talk_video(): void
    {
        config(['church.sermons.childrens_talks.public' => false]);

        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'video_file_path' => 'sermons/childrens-talk.mp4',
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/video");

        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function guest_is_redirected_when_accessing_non_public_childrens_talk_card_thumbnail(): void
    {
        config(['church.sermons.childrens_talks.public' => false]);

        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'thumbnail_metadata' => [
                'card_thumbnail_path' => 'thumbnails/card.webp',
            ],
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail/card");

        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function authenticated_user_can_access_non_public_childrens_talk_audio(): void
    {
        Storage::fake('public');
        config(['church.sermons.childrens_talks.public' => false]);

        $user = User::factory()->create();
        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'sermons/childrens-talk.mp3',
        ]);

        Storage::disk('public')->put('sermons/childrens-talk.mp3', 'fake audio');

        $response = $this->actingAs($user)->get("/christ/sermons/{$sermon->slug}/audio");

        $response->assertRedirect();
        $this->assertStringContainsString('childrens-talk.mp3', $response->headers->get('Location'));
    }

    #[Test]
    public function guest_can_access_public_childrens_talk_audio(): void
    {
        Storage::fake('public');
        config(['church.sermons.childrens_talks.public' => true]);

        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'sermons/childrens-talk.mp3',
        ]);

        Storage::disk('public')->put('sermons/childrens-talk.mp3', 'fake audio');

        $response = $this->get("/christ/sermons/{$sermon->slug}/audio");

        $response->assertRedirect();
        $this->assertStringContainsString('childrens-talk.mp3', $response->headers->get('Location'));
    }

    #[Test]
    public function guest_can_access_public_childrens_talk_video(): void
    {
        Storage::fake('public');
        config(['church.sermons.childrens_talks.public' => true]);

        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'video_file_path' => 'sermons/childrens-talk.mp4',
        ]);

        Storage::disk('public')->put('sermons/childrens-talk.mp4', 'fake video');

        $response = $this->get("/christ/sermons/{$sermon->slug}/video");

        $response->assertRedirect();
        $this->assertStringContainsString('childrens-talk.mp4', $response->headers->get('Location'));
    }

    #[Test]
    public function it_serves_private_audio_file_as_binary_response_to_admin(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();

        $sermon = Sermon::factory()->create([
            'slug' => 'private-sermon',
            'audio_file_path' => 'private/sermons/test-audio.mp3',
        ]);

        Storage::disk('local')->put('private/sermons/test-audio.mp3', 'fake private audio content');

        $response = $this->actingAs($admin)->get("/christ/sermons/{$sermon->slug}/audio");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'audio/mpeg');
    }

    #[Test]
    public function it_serves_private_video_file_as_binary_response_to_admin(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();

        $sermon = Sermon::factory()->create([
            'slug' => 'private-video-sermon',
            'video_file_path' => 'private/sermons/test-video.mp4',
        ]);

        Storage::disk('local')->put('private/sermons/test-video.mp4', 'fake private video content');

        $response = $this->actingAs($admin)->get("/christ/sermons/{$sermon->slug}/video");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'video/mp4');
        $response->assertHeaderContains('Cache-Control', 'no-store');
    }

    #[Test]
    public function it_serves_private_thumbnail_file_as_binary_response_to_admin(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();

        $sermon = Sermon::factory()->create([
            'slug' => 'private-thumb-sermon',
            'thumbnail_file_path' => 'private/thumbnails/test-thumb.png',
        ]);

        Storage::disk('local')->put('private/thumbnails/test-thumb.png', 'fake private png content');

        $response = $this->actingAs($admin)->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
    }

    #[Test]
    public function it_serves_private_card_thumbnail_file_as_binary_response_to_admin(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();

        $sermon = Sermon::factory()->create([
            'slug' => 'private-card-thumb-sermon',
            'thumbnail_metadata' => [
                'card_thumbnail_path' => 'private/thumbnails/card.webp',
            ],
        ]);

        Storage::disk('local')->put('private/thumbnails/card.webp', 'fake private webp content');

        $response = $this->actingAs($admin)->get("/christ/sermons/{$sermon->slug}/thumbnail/card");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/webp');
    }

    // ── Transcript endpoint ───────────────────────────────────────────────────

    #[Test]
    public function it_returns_rendered_html_for_a_sermon_transcript(): void
    {
        $sermon = Sermon::factory()->create(['slug' => 'transcript-sermon', 'transcript_file_path' => 'transcripts/sample.txt']);

        $reader = $this->createStub(SermonTranscriptReader::class);
        $reader->method('read')->willReturn("Hello **world**.\n\nSecond paragraph.");
        $this->app->instance(SermonTranscriptReader::class, $reader);

        $response = $this->get('/christ/sermons/transcript-sermon/transcript');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->assertSee('<strong>world</strong>', false);
    }

    #[Test]
    public function it_returns_404_when_sermon_has_no_transcript(): void
    {
        $sermon = Sermon::factory()->create(['slug' => 'no-transcript-sermon', 'transcript_file_path' => null]);

        $response = $this->get('/christ/sermons/no-transcript-sermon/transcript');

        $response->assertNotFound();
    }

    #[Test]
    public function transcript_endpoint_redirects_unauthenticated_user_for_non_public_childrens_talk(): void
    {
        config(['church.sermons.childrens_talks.public' => false]);

        $sermon = Sermon::factory()->create([
            'slug' => 'private-ct-transcript',
            'content_type' => SermonContentType::ChildrensTalk,
            'transcript_file_path' => 'transcripts/ct.txt',
        ]);

        $response = $this->get('/christ/sermons/private-ct-transcript/transcript');

        $response->assertRedirect(route('login'));
    }

    // ── Plain thumbnail endpoint ─────────────────────────────────────────────

    #[Test]
    public function it_serves_plain_thumbnail_successfully(): void
    {
        Storage::fake('public');
        config(['thumbnail-generation.storage.disk' => 'public']);

        $sermon = Sermon::factory()->create([
            'slug' => 'plain-test-sermon',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'thumbnails/plain.webp',
            ],
        ]);

        Storage::disk('public')->put('thumbnails/plain.webp', 'fake webp content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail/plain");

        $response->assertRedirect(app(SermonStorageService::class)->getPlainThumbnailUrl($sermon));
    }

    #[Test]
    public function it_returns_404_when_plain_thumbnail_is_missing(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'no-plain-test-sermon',
            'thumbnail_metadata' => null,
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail/plain");

        $response->assertStatus(404);
    }

    #[Test]
    public function it_returns_404_when_plain_thumbnail_file_missing_on_disk(): void
    {
        Storage::fake('public');
        config(['thumbnail-generation.storage.disk' => 'public']);

        $sermon = Sermon::factory()->create([
            'slug' => 'missing-file-plain-sermon',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'thumbnails/missing-on-disk.webp',
            ],
        ]);

        // We DO NOT put the file on the fake disk

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail/plain");

        $response->assertStatus(404);
    }

    #[Test]
    public function it_returns_404_when_plain_thumbnail_path_is_unsafe(): void
    {
        Storage::fake('public');
        config(['thumbnail-generation.storage.disk' => 'public']);

        $sermon = Sermon::factory()->create([
            'slug' => 'unsafe-file-plain-sermon',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'thumbnails/../../etc/passwd',
            ],
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail/plain");

        $response->assertStatus(404);
    }

    #[Test]
    public function it_serves_private_plain_thumbnail_file_as_binary_response_to_admin(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();

        $sermon = Sermon::factory()->create([
            'slug' => 'private-plain-thumb-sermon',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'private/thumbnails/plain.webp',
            ],
        ]);

        Storage::disk('local')->put('private/thumbnails/plain.webp', 'fake private webp content');

        $response = $this->actingAs($admin)->get("/christ/sermons/{$sermon->slug}/thumbnail/plain");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/webp');
    }

    #[Test]
    public function guest_is_redirected_when_accessing_non_public_childrens_talk_plain_thumbnail(): void
    {
        config(['church.sermons.childrens_talks.public' => false]);

        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'thumbnails/plain.webp',
            ],
        ]);

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail/plain");

        $response->assertRedirect(route('login'));
    }
}
