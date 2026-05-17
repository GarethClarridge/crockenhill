<?php

declare(strict_types=1);

namespace Tests\Feature;

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
        $response->assertSee('Recent Songs | Crockenhill Baptist Church');
        $response->assertSee('Browse the songs most recently sung at Crockenhill Baptist Church.');
        $response->assertSee(url('church/songs'));
    }

    public function test_song_archive_has_correct_seo_metadata_for_search(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('church.songs.index', ['q' => 'Grace']));

        $response->assertStatus(200);
        $response->assertSee('Search: Grace | Songs | Crockenhill Baptist Church');
        $response->assertSee('Browse songs matching');
        $response->assertSee('Grace');
        $response->assertSee(url('church/songs?q=Grace'));
    }

    public function test_song_archive_has_correct_seo_metadata_for_all_time_range(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('church.songs.index', ['range' => PublicSongCatalogService::RANGE_ALL]));

        $response->assertStatus(200);
        $response->assertSee('All Songs | Crockenhill Baptist Church');
        $response->assertSee('Browse the full song catalogue of Crockenhill Baptist Church.');
        $response->assertSee(url('church/songs?range=all'));
    }

    public function test_livewire_component_updates_seo_properties_on_filter_change(): void
    {
        $user = User::factory()->create();
        $test = Livewire::actingAs($user)->test(\App\Livewire\Church\Songs\BrowseSongs::class);

        $this->assertEquals('Recent Songs', $test->get('seoTitle'));

        $test->set('search', 'Amazing');

        $this->assertEquals('Search: Amazing | Songs', $test->get('seoTitle'));
        $this->assertEquals("Browse songs matching 'Amazing' at Crockenhill Baptist Church.", $test->get('seoDescription'));

        $test->set('search', '')
            ->set('range', PublicSongCatalogService::RANGE_ALL);

        $this->assertEquals('All Songs', $test->get('seoTitle'));
        $this->assertEquals('Browse the full song catalogue of Crockenhill Baptist Church.', $test->get('seoDescription'));
    }
}
