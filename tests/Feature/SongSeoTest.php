<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Church\Songs\BrowseSongs;
use App\Models\Song;
use App\Models\SongAuthor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongSeoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function song_archive_has_correct_default_seo_metadata(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('church.songs.index'));

        $response->assertStatus(200);
        $response->assertSee('Recent Songs | Crockenhill Baptist Church');
        $response->assertSee('Browse the songs most recently sung at Crockenhill Baptist Church.');
        $response->assertSee('http://localhost/church/songs');
    }

    #[Test]
    public function song_archive_has_correct_seo_metadata_for_search(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('church.songs.index', ['q' => 'Grace', 'range' => 'all']));

        $response->assertStatus(200);
        $response->assertSee('Grace | Songs | Crockenhill Baptist Church');
        $response->assertSee("Browse songs matching 'Grace' at Crockenhill Baptist Church.");
        $response->assertSee('http://localhost/church/songs?q=Grace&amp;range=all', false);
    }

    #[Test]
    public function song_archive_has_correct_seo_metadata_for_all_time_range(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('church.songs.index', ['range' => 'all']));

        $response->assertStatus(200);
        $response->assertSee('All Songs | Crockenhill Baptist Church');
        $response->assertSee('Browse the full song catalogue of Crockenhill Baptist Church.');
        $response->assertSee('http://localhost/church/songs?range=all');
    }

    #[Test]
    public function livewire_component_updates_seo_properties_on_filter_change(): void
    {
        Livewire::test(BrowseSongs::class)
            ->set('search', 'Amazing')
            ->assertSet('seoTitle', 'Amazing | Songs')
            ->set('search', '')
            ->set('range', 'all')
            ->assertSet('seoTitle', 'All Songs');
    }

    #[Test]
    public function song_archive_prevents_crash_on_array_query_params(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('church.songs.index', ['q' => ['grace']]));

        $response->assertStatus(200);
        $response->assertSee('Recent Songs | Crockenhill Baptist Church');
    }

    #[Test]
    public function song_archive_preserves_falsy_search_in_canonical(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('church.songs.index', ['q' => '0', 'range' => 'all']));

        $response->assertStatus(200);
        $response->assertSee('http://localhost/church/songs?q=0&amp;range=all', false);
    }

    #[Test]
    public function song_detail_page_has_correct_metadata_and_structured_data(): void
    {
        $user = User::factory()->create();
        $author = SongAuthor::factory()->create(['display_name' => 'Stuart Townend']);
        $song = Song::factory()->create([
            'title' => 'In Christ Alone',
            'slug' => 'in-christ-alone',
        ]);
        $song->authors()->attach($author);

        $response = $this->actingAs($user)->get(route('church.songs.show', $song));

        $response->assertStatus(200);
        $response->assertSee('In Christ Alone | Songs | Crockenhill Baptist Church');
        $response->assertSee('Lyrics and recent usage for In Christ Alone at Crockenhill Baptist Church.');
        $response->assertSee('<meta property="og:title" content="In Christ Alone | Songs | Crockenhill Baptist Church">', false);

        // MusicComposition Schema
        $response->assertSee('"@type": "MusicComposition"', false);
        $response->assertSee('"name": "In Christ Alone"', false);
        $response->assertSee('"url": "http://localhost/church/songs/in-christ-alone"', false);
        $response->assertSee('"@type": "Person"', false);
        $response->assertSee('"name": "Stuart Townend"', false);
    }

    #[Test]
    public function song_index_has_item_list_structured_data(): void
    {
        $user = User::factory()->create();
        $author = SongAuthor::factory()->create(['display_name' => 'Author Name']);
        $songs = Song::factory()->count(3)->create();

        foreach ($songs as $song) {
            $song->authors()->attach($author);
        }

        $response = $this->actingAs($user)->get(route('church.songs.index', ['range' => 'all']));

        $response->assertStatus(200);
        $response->assertSee('"@type": "ItemList"', false);
        $response->assertSee('"numberOfItems": 3', false);
        $response->assertSee('"@type": "MusicComposition"', false);
        $response->assertSee($songs->first()->title);
        $response->assertSee('Author Name');
    }

    #[Test]
    public function song_search_results_have_correct_item_list_count(): void
    {
        $user = User::factory()->create();
        Song::factory()->create(['title' => 'Amazing Grace']);
        Song::factory()->create(['title' => 'How Great Thou Art']);

        $response = $this->actingAs($user)->get(route('church.songs.index', ['q' => 'Grace', 'range' => 'all']));

        $response->assertStatus(200);
        $response->assertSee('"@type": "ItemList"', false);
        $response->assertSee('"numberOfItems": 1', false);
        $response->assertSee('Amazing Grace');
        $response->assertDontSee('How Great Thou Art');
    }
}
