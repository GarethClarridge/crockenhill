<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BindConfirmedSongIdentitiesCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * BC-06's shape: the item names the song and the catalogue carries exactly
     * that title, so the section reads `confirmed` with nothing behind it.
     */
    #[Test]
    public function it_binds_a_deterministic_match_to_the_item_a_confirmed_section_depends_on(): void
    {
        $song = Song::factory()->create([
            'canonical_key' => 'all of us in sin were dying',
            'title' => 'All Of Us In Sin Were Dying #686',
        ]);
        $item = $this->unboundItem('All of Us in Sin Were Dying');
        $this->confirmedSongSection($item);

        $this->artisan('service-tracking:bind-confirmed-song-identities')->assertExitCode(0);

        $item->refresh();
        $this->assertSame($song->id, $item->song_id);
        $this->assertSame('All Of Us In Sin Were Dying #686', $item->title, 'The catalogue title becomes the item title.');
    }

    /**
     * A resemblance is not an identity. The corpus already holds sections
     * marked confirmed against the wrong song, so an inferred match is reported
     * for adjudication rather than written.
     */
    #[Test]
    public function it_leaves_an_inferred_match_unbound_and_reports_it(): void
    {
        Song::factory()->create([
            'canonical_key' => 'from the squalor of a broken stable a',
            'title' => 'From The Squalor Of A Broken Stable A',
        ]);
        $item = $this->unboundItem('From the Squalor of a Broken Stable');
        $this->confirmedSongSection($item);

        $this->artisan('service-tracking:bind-confirmed-song-identities')
            ->expectsOutputToContain('adjudicate these by hand')
            ->assertExitCode(0);

        $this->assertNull($item->refresh()->song_id);
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        Song::factory()->create([
            'canonical_key' => 'all of us in sin were dying',
            'title' => 'All Of Us In Sin Were Dying #686',
        ]);
        $item = $this->unboundItem('All of Us in Sin Were Dying');
        $this->confirmedSongSection($item);

        $this->artisan('service-tracking:bind-confirmed-song-identities', ['--dry-run' => true])
            ->expectsOutputToContain('nothing was written')
            ->assertExitCode(0);

        $this->assertNull($item->refresh()->song_id);
    }

    /**
     * The scope is what a confirmed section asserts. An unbound item no section
     * confirms is the wider catalogue's business, and
     * `service-tracking:link-songs` is the command for that — a dry run of it
     * over the whole catalogue reports hundreds of changes.
     */
    #[Test]
    public function it_ignores_an_unbound_item_that_no_confirmed_section_depends_on(): void
    {
        Song::factory()->create([
            'canonical_key' => 'all of us in sin were dying',
            'title' => 'All Of Us In Sin Were Dying #686',
        ]);
        $item = $this->unboundItem('All of Us in Sin Were Dying');

        $this->artisan('service-tracking:bind-confirmed-song-identities')->assertExitCode(0);

        $this->assertNull($item->refresh()->song_id);
    }

    #[Test]
    public function it_ignores_a_section_whose_song_match_is_not_confirmed(): void
    {
        Song::factory()->create([
            'canonical_key' => 'all of us in sin were dying',
            'title' => 'All Of Us In Sin Were Dying #686',
        ]);
        $item = $this->unboundItem('All of Us in Sin Were Dying');
        $this->confirmedSongSection($item, matchType: 'inferred');

        $this->artisan('service-tracking:bind-confirmed-song-identities')->assertExitCode(0);

        $this->assertNull($item->refresh()->song_id);
    }

    private function unboundItem(string $title): ChurchServiceItem
    {
        return ChurchServiceItem::factory()->create([
            'type' => 'songs',
            'title' => $title,
            'openlp_search_title' => null,
            'source_title' => null,
            'song_id' => null,
        ]);
    }

    private function confirmedSongSection(ChurchServiceItem $item, string $matchType = 'confirmed'): ServiceSection
    {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => MediaProcessingLog::factory()->livestream()->create()->id,
            'church_service_item_id' => $item->id,
            'section_type' => 'song',
            'song_match_type' => $matchType,
            'section_order' => 1,
        ]);
    }
}
