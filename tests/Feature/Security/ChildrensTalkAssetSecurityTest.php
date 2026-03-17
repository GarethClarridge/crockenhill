<?php

namespace Tests\Feature\Security;

use App\Enums\SermonContentType;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChildrensTalkAssetSecurityTest extends TestCase
{
    use RefreshDatabase;

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

        $user = \App\Models\User::factory()->create();

        $response = $this->actingAs($user)->get("/christ/sermons/{$sermon->slug}/audio");

        $response->assertStatus(200);
    }
}
