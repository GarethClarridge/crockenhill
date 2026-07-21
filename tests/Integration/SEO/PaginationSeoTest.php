<?php

declare(strict_types=1);

namespace Tests\Integration\SEO;

use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaginationSeoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sermon_archive_page_2_has_correct_title(): void
    {
        Sermon::factory()->count(30)->create();

        $response = $this->get('/christ/sermons?page=2');

        $response->assertStatus(200);

        // Robust assertion targeting the content of the title tag
        $this->assertMatchesRegularExpression('/<title>[^<]*Sermons \(Page 2\) \| Crockenhill Baptist Church[^<]*<\/title>/i', $response->getContent());

        // Robust assertions for description presence & page suffix
        $response->assertSee('<meta name="description"', false);
        $response->assertSee('Explore the sermon archive at Crockenhill Baptist Church', false);
        $response->assertSee('- Page 2', false);
    }

    #[Test]
    public function song_archive_page_2_has_correct_title(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $response = $this->actingAs($user)->get('/church/songs?page=2');

        $response->assertStatus(200);

        // Robust assertion targeting the content of the title tag
        $this->assertMatchesRegularExpression('/<title>[^<]*Recent Songs \(Page 2\) \| Crockenhill Baptist Church[^<]*<\/title>/i', $response->getContent());

        // Robust assertions for description presence & page suffix
        $response->assertSee('<meta name="description"', false);
        $response->assertSee('Browse the songs most recently sung at Crockenhill Baptist Church', false);
        $response->assertSee('- Page 2', false);
    }
}
