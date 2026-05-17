<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Church\Songs\BrowseSongs;
use App\Models\User;
use App\Services\PublicSongCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SongArchiveSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_song_archive_has_correct_default_seo_metadata(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('church.songs.index'));

        $response->assertStatus(200);
        $response->assertSee('<title>Recent Songs | Crockenhill Baptist Church</title>', false);
        $response->assertSee('<meta name="description" content="Browse the songs most recently sung at Crockenhill Baptist Church.">', false);
        $response->assertSee('<link rel="canonical" href="'.url('church/songs').'">', false);
    }

    public function test_song_archive_has_correct_seo_metadata_for_search(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('church.songs.index', ['q' => 'Grace', 'range' => PublicSongCatalogService::RANGE_ALL]));

        $response->assertStatus(200);
        $response->assertSee('<title>Grace | Songs | Crockenhill Baptist Church</title>', false);
        $response->assertSee('<meta name="description" content="Browse songs matching &#039;Grace&#039; at Crockenhill Baptist Church.">', false);
        $response->assertSee('<link rel="canonical" href="http://localhost/church/songs?q=Grace&amp;range=all">', false);
    }

    public function test_song_archive_has_correct_seo_metadata_for_all_time_range(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('church.songs.index', ['range' => PublicSongCatalogService::RANGE_ALL]));

        $response->assertStatus(200);
        $response->assertSee('<title>All Songs | Crockenhill Baptist Church</title>', false);
        $response->assertSee('<meta name="description" content="Browse the full song catalogue of Crockenhill Baptist Church.">', false);
        $response->assertSee('<link rel="canonical" href="'.url('church/songs?range=all').'">', false);
    }

    public function test_song_archive_prevents_crash_on_array_query_params(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('church.songs.index', ['q' => ['grace']]));

        $response->assertStatus(200);
        $response->assertSee('<title>Recent Songs | Crockenhill Baptist Church</title>', false);
    }

    public function test_song_archive_preserves_falsy_search_in_canonical(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('church.songs.index', ['q' => '0', 'range' => 'all']));

        $response->assertStatus(200);
        $response->assertSee('<link rel="canonical" href="http://localhost/church/songs?q=0&amp;range=all">', false);
    }

    public function test_livewire_component_updates_seo_properties_on_filter_change(): void
    {
        $user = User::factory()->create();
        $test = Livewire::actingAs($user)->test(BrowseSongs::class);

        $this->assertEquals('Recent Songs', $test->get('seoTitle'));

        $test->set('search', 'Amazing')
            ->set('range', PublicSongCatalogService::RANGE_ALL);

        $this->assertEquals('Amazing | Songs', $test->get('seoTitle'));
        $this->assertEquals("Browse songs matching 'Amazing' at Crockenhill Baptist Church.", $test->get('seoDescription'));

        $test->set('search', '')
            ->set('range', PublicSongCatalogService::RANGE_ALL);

        $this->assertEquals('All Songs', $test->get('seoTitle'));
        $this->assertEquals('Browse the full song catalogue of Crockenhill Baptist Church.', $test->get('seoDescription'));
    }
}
