<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\SermonContentType;
use App\Jobs\MoveSermonToPrivateStorage;
use App\Models\Sermon;
use App\Models\User;
use App\Services\Sermon\SermonStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChildrensTalkAssetSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([MoveSermonToPrivateStorage::class]);
    }

    #[Test]
    public function it_redirects_unauthorized_access_to_childrens_talk_audio_to_login(): void
    {
        Storage::fake('public');

        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'sermons/childrens-talk.mp3',
        ]);

        Storage::disk('public')->put('sermons/childrens-talk.mp3', 'fake audio content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/audio");

        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function it_redirects_unauthorized_access_to_childrens_talk_thumbnail_to_login(): void
    {
        Storage::fake('public');

        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'thumbnail_file_path' => 'thumbnails/childrens-talk.webp',
        ]);

        Storage::disk('public')->put('thumbnails/childrens-talk.webp', 'fake webp content');

        $response = $this->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function it_allows_authorized_access_to_childrens_talk_audio(): void
    {
        Storage::fake('public');

        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'sermons/childrens-talk.mp3',
        ]);

        Storage::disk('public')->put('sermons/childrens-talk.mp3', 'fake audio content');

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get("/christ/sermons/{$sermon->slug}/audio");

        $response->assertRedirect(app(SermonStorageService::class)->getPublicUrl($sermon));
    }

    #[Test]
    public function it_is_not_accessible_via_raw_storage_path(): void
    {
        Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'private/sermons/audio/test-childrens-talk.mp3',
        ]);

        $response = $this->get('/storage/private/sermons/audio/test-childrens-talk.mp3');

        $response->assertStatus(404);
    }
}
