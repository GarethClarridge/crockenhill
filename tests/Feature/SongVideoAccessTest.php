<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonPublicationState;
use App\Models\SongVideo;
use App\Models\User;
use App\Services\Song\SongVideoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongVideoAccessTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function guests_and_unverified_users_cannot_fetch_song_media(): void
    {
        $video = SongVideo::factory()->create();
        $url = url('/church/song-videos/'.$video->id);
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['email_verified_at' => null]))
            ->get($url)->assertRedirect(route('verification.notice'));
    }

    #[Test]
    public function published_cloud_video_redirects_to_a_short_lived_url_from_its_recorded_disk(): void
    {
        $this->freezeTime();
        $disk = Storage::fake('song_cloud');
        config(['filesystems.disks.song_cloud.driver' => 's3']);
        $path = 'sermons/songs/1/published.mp4';
        $disk->put($path, 'video');
        $disk->buildTemporaryUrlsUsing(function (string $actualPath, \DateTimeInterface $expires) use ($path): string {
            Assert::assertSame($path, $actualPath);
            Assert::assertSame(now()->addMinutes(5)->getTimestamp(), $expires->getTimestamp());

            return 'https://storage.example/private-video?signature=short-lived';
        });
        $video = SongVideo::factory()->create(['asset_disk' => 'song_cloud', 'video_file_path' => $path]);

        $this->actingAs(User::factory()->create())
            ->get(url('/church/song-videos/'.$video->id))
            ->assertRedirect('https://storage.example/private-video?signature=short-lived')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    #[Test]
    public function local_video_is_served_inline_through_the_authenticated_route(): void
    {
        Storage::fake('local')->put('sermons/songs/1/local.mp4', 'video');
        $video = SongVideo::factory()->create(['asset_disk' => 'local', 'video_file_path' => 'sermons/songs/1/local.mp4']);

        $this->actingAs(User::factory()->create())
            ->get(url('/church/song-videos/'.$video->id))
            ->assertOk()
            ->assertHeader('Content-Type', 'video/mp4')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->get(url('/church/song-videos/'.$video->id), ['Range' => 'bytes=0-1'])
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 0-1/5');
    }

    #[Test]
    public function held_missing_unsafe_and_staging_assets_are_not_served(): void
    {
        Storage::fake('local');
        Storage::fake('historic_staging');
        $this->actingAs(User::factory()->create());
        foreach ([
            ['publication_state' => SermonPublicationState::Quarantined],
            ['video_file_path' => 'missing.mp4'],
            ['video_file_path' => '../secrets.mp4'],
            ['asset_disk' => 'historic_staging'],
        ] as $attributes) {
            $video = SongVideo::factory()->create(['asset_disk' => 'local', ...$attributes]);
            $this->get(url('/church/song-videos/'.$video->id))->assertNotFound();
        }
    }

    #[Test]
    public function player_urls_point_at_the_gate_instead_of_storage(): void
    {
        $video = SongVideo::factory()->create();

        $this->assertSame(url('/church/song-videos/'.$video->id), app(SongVideoService::class)->getVideoUrl($video));
    }
}
