<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Song;
use App\Models\SongUsageReport;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class PublicSongUsageReportTest extends DuskTestCase
{
    use DatabaseTruncation;

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
