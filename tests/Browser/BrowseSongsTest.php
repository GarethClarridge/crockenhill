<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\Test;
use Tests\DuskTestCase;

class BrowseSongsTest extends DuskTestCase
{
    use DatabaseTruncation;

    #[Test]
    public function hymnbook_toggle_filters_songs_and_survives_reload(): void
    {
        Song::factory()->create(['title' => 'Numbered Hymn', 'praise_number' => '123']);
        Song::factory()->create(['title' => 'Unnumbered Song', 'praise_number' => null]);
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user): void {
            $browser->loginAs($user)
                ->resize(390, 844)
                ->visit('/church/songs?range=all')
                ->assertSee('Numbered Hymn')
                ->assertSee('Unnumbered Song')
                ->keys('#notInPraise', '{SPACE}')
                ->waitUntilMissingText('Numbered Hymn')
                ->assertSee('Unnumbered Song')
                ->assertAttribute('#notInPraise', 'aria-checked', 'true')
                ->screenshot('song-hymnbook-filter-mobile')
                ->assertQueryStringHas('not-in-praise', 'true')
                ->refresh()
                ->assertDontSee('Numbered Hymn')
                ->assertSee('Unnumbered Song')
                ->assertAttribute('#notInPraise', 'aria-checked', 'true')
                ->click('#notInPraise')
                ->waitForText('Numbered Hymn')
                ->assertAttribute('#notInPraise', 'aria-checked', 'false')
                ->assertQueryStringMissing('not-in-praise');
        });
    }
}
