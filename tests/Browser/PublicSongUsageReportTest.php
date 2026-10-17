<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Song;
use App\Models\SongUsageReport;
use App\Models\SongVideo;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\Test;
use Tests\DuskTestCase;

class PublicSongUsageReportTest extends DuskTestCase
{
    use DatabaseTruncation;

    #[Test]
    public function song_player_uses_the_authenticated_media_gate(): void
    {
        $user = User::factory()->create();
        $song = Song::factory()->create();
        $video = SongVideo::factory()->create([
            'song_id' => $song->id,
            'asset_disk' => 'local',
            'video_file_path' => 'sermons/songs/browser-playback.mp4',
        ]);

        $this->browse(function (Browser $browser) use ($user, $song, $video): void {
            $browser->loginAs($user)
                ->visit("/church/songs/{$song->slug}")
                ->assertAttribute('video', 'src', route('church.song-videos.show', $video))
                ->logout()
                ->visitRoute('church.song-videos.show', $video)
                ->waitForLocation('/login');
        });
    }

    public function test_date_only_song_usage_is_shown_without_a_service_link(): void
    {
        $user = User::factory()->create();
        $song = Song::factory()->create(['slug' => 'historic-hymn']);

        SongUsageReport::factory()->create([
            'song_id' => $song->id,
            'used_on' => '2007-06-17',
            'reported_service' => null,
            'reported_title' => 'Historic hymn title',
        ]);

        $this->browse(function (Browser $browser) use ($user, $song): void {
            $browser->loginAs($user)
                ->visit("/church/songs/{$song->slug}")
                ->assertSee('17 Jun 2007')
                ->assertSee('Service not recorded')
                ->assertSee('Historic hymn title')
                ->assertMissing('a[href*="/church/services/2007-06-17"]');
        });
    }
}
