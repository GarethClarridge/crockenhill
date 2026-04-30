<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Song;
use App\Models\SongAuthor;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongSeoTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function song_index_has_correct_metadata()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('church.songs.index'));

        $response->assertStatus(200);
        $response->assertSee('<title>Songs | Crockenhill Baptist Church</title>', false);
        $response->assertSee('<meta name="description" content="Browse the songs most often sung at Crockenhill Baptist Church.">', false);
        $response->assertSee('<meta property="og:title" content="Songs | Crockenhill Baptist Church">', false);
        $response->assertSee('<meta property="og:description" content="Browse the songs most often sung at Crockenhill Baptist Church.">', false);
        $response->assertSee('"@type": "WebPage"', false);
        $response->assertSee('"name": "Songs"', false);
    }

    #[Test]
    public function song_detail_page_has_correct_metadata_and_structured_data()
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
        $response->assertSee('<title>In Christ Alone | Songs | Crockenhill Baptist Church</title>', false);
        $response->assertSee('<meta name="description" content="Lyrics and recent usage for In Christ Alone at Crockenhill Baptist Church.">', false);
        $response->assertSee('<meta property="og:title" content="In Christ Alone | Songs | Crockenhill Baptist Church">', false);

        // MusicComposition Schema
        $response->assertSee('"@type": "MusicComposition"', false);
        $response->assertSee('"name": "In Christ Alone"', false);
        $response->assertSee('"url": "http://localhost/church/songs/in-christ-alone"', false);
        $response->assertSee('"@type": "Person"', false);
        $response->assertSee('"name": "Stuart Townend"', false);
    }
}
