<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\SermonContentType;
use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PrivateSermonAssetSecurityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    #[Test]
    public function guest_cannot_access_regular_sermon_with_private_audio(): void
    {
        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::Sermon,
            'audio_file_path' => 'private/secret-audio.mp3',
        ]);
        Storage::disk('local')->put('private/secret-audio.mp3', 'secret content');

        $response = $this->get(route('sermons.audio', $sermon));

        $response->assertStatus(403);
    }

    #[Test]
    public function guest_cannot_access_regular_sermon_with_private_video(): void
    {
        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::Sermon,
            'video_file_path' => 'private/secret-video.mp4',
        ]);
        Storage::disk('local')->put('private/secret-video.mp4', 'secret content');

        $response = $this->get(route('sermons.video', $sermon));

        $response->assertStatus(403);
    }

    #[Test]
    public function guest_cannot_access_regular_sermon_with_private_thumbnail(): void
    {
        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::Sermon,
            'thumbnail_file_path' => 'private/secret-thumb.webp',
        ]);
        Storage::disk('local')->put('private/secret-thumb.webp', 'secret content');

        $response = $this->get(route('sermons.thumbnail', $sermon));

        $response->assertStatus(403);
    }

    #[Test]
    public function regular_user_cannot_access_regular_sermon_with_private_audio(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::Sermon,
            'audio_file_path' => 'private/secret-audio.mp3',
        ]);
        Storage::disk('local')->put('private/secret-audio.mp3', 'secret content');

        $response = $this->actingAs($user)->get(route('sermons.audio', $sermon));

        $response->assertStatus(403);
    }

    #[Test]
    public function admin_can_access_regular_sermon_with_private_audio(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::Sermon,
            'audio_file_path' => 'private/secret-audio.mp3',
        ]);
        Storage::disk('local')->put('private/secret-audio.mp3', 'secret content');

        $response = $this->actingAs($admin)->get(route('sermons.audio', $sermon));

        $response->assertStatus(200);
    }

    #[Test]
    public function guest_redirected_to_login_for_private_childrens_talk_even_if_not_on_private_disk(): void
    {
        // This confirms we still respect Children's Corner policies
        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'sermons/talk.mp3',
        ]);
        Storage::disk('public')->put('sermons/talk.mp3', 'public content');

        config(['sermons.childrens_talks.public' => false]);

        $response = $this->get(route('sermons.audio', $sermon));

        $response->assertRedirect(route('login'));
    }
}
