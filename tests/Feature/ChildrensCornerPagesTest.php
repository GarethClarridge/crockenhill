<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonContentType;
use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChildrensCornerPagesTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function guests_are_redirected_to_login(): void
    {
        $this->get('/christ/childrens-corner')->assertRedirect('/login');

        $talk = Sermon::factory()->create([
            'slug' => 'guest-talk',
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        $this->get("/christ/childrens-corner/{$talk->slug}")->assertRedirect('/login');
    }

    #[Test]
    public function listing_shows_only_childrens_talks(): void
    {
        $this->actingAs(User::factory()->create());

        Sermon::factory()->create([
            'title' => "Children's Talk One",
            'content_type' => SermonContentType::ChildrensTalk,
        ]);
        Sermon::factory()->create([
            'title' => 'Sunday Sermon',
            'content_type' => SermonContentType::Sermon,
        ]);

        $response = $this->get('/christ/childrens-corner');

        $response->assertStatus(200);
        $response->assertSee("Children's Talk One");
        $response->assertDontSee('Sunday Sermon');
        $response->assertSee('Browse full sermon library');

        // SEO: Check title and meta description
        $response->assertSee('Children&#039;s Corner | Crockenhill Baptist Church');
        $response->assertSee('Short Bible talks for children');

        // SEO: Check ItemList JSON-LD
        $response->assertSee('"@type": "ItemList"', false);
        $response->assertSee('Children&#039;s Talk One', false);
    }

    #[Test]
    public function detail_page_renders_for_childrens_talk(): void
    {
        $this->actingAs(User::factory()->create());

        $talk = Sermon::factory()->create([
            'title' => 'Little Listeners',
            'slug' => 'little-listeners',
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'sermons/audio/little-listeners.mp3',
            'video_file_path' => 'sermons/video/little-listeners.mp4',
            'show_summary' => true,
            'summary' => 'This should not appear on the simplified page.',
        ]);

        $response = $this->get("/christ/childrens-corner/{$talk->slug}");

        $response->assertStatus(200);
        $response->assertSee('Little Listeners');
        $response->assertSee('Watch');
        $response->assertSee('Listen');
        $response->assertDontSee('Summary');

        // SEO: Check speaker-enriched title
        $talk->load('preacherProfile');
        $speakerName = $talk->displayPreacherName();
        $response->assertSee("Little Listeners | {$speakerName} | Crockenhill Baptist Church");

        // SEO: Check Sermon structured data (Article)
        $response->assertSee('"@type": "Article"', false);
        $response->assertSee('"headline": "Little Listeners"', false);
        $response->assertSee('"publisher":', false);
    }

    #[Test]
    public function guests_can_access_childrens_corner_when_public_release_is_enabled(): void
    {
        config(['sermons.childrens_talks.public' => true]);

        $talk = Sermon::factory()->create([
            'title' => 'Public Little Listeners',
            'slug' => 'public-little-listeners',
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        $this->get('/christ/childrens-corner')
            ->assertOk()
            ->assertSee('Public Little Listeners');

        $this->get("/christ/childrens-corner/{$talk->slug}")
            ->assertOk()
            ->assertSee('Public Little Listeners');
    }

    #[Test]
    public function sermon_records_return_not_found_on_childrens_corner_detail_route(): void
    {
        $this->actingAs(User::factory()->create());

        $sermon = Sermon::factory()->create([
            'slug' => 'main-sermon',
            'content_type' => SermonContentType::Sermon,
        ]);

        $this->get("/christ/childrens-corner/{$sermon->slug}")
            ->assertNotFound();
    }

    #[Test]
    public function listing_paginates_results(): void
    {
        $this->actingAs(User::factory()->create());

        // Ensure we start with a clean slate for pagination counts
        Sermon::query()->where('content_type', SermonContentType::ChildrensTalk)->delete();

        Sermon::factory()->count(13)->create([
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        $page1 = $this->get('/christ/childrens-corner');
        $page1->assertStatus(200);
        $page1->assertViewHas('talks', fn ($talks) => $talks->count() === 12);

        $page2 = $this->get('/christ/childrens-corner?page=2');
        $page2->assertStatus(200);
        $page2->assertViewHas('talks', fn ($talks) => $talks->count() === 1);
    }

    #[Test]
    public function childrens_talks_return_not_found_on_sermon_routes_when_public_release_is_disabled(): void
    {
        $talk = Sermon::factory()->create([
            'slug' => 'private-childrens-talk',
            'date' => '2026-02-01',
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        $this->get(route('showSermon', $talk))
            ->assertNotFound();

        $this->get(route('showSermonWithDate', [
            'year' => '2026',
            'month' => '02',
            'sermon' => $talk->slug,
        ]))->assertNotFound();
    }

    #[Test]
    public function public_childrens_talks_redirect_from_sermon_routes_to_childrens_corner(): void
    {
        config(['sermons.childrens_talks.public' => true]);

        $talk = Sermon::factory()->create([
            'slug' => 'redirect-childrens-talk',
            'date' => '2026-02-01',
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        $this->get(route('showSermon', $talk))
            ->assertRedirect(route('childrens-corner.show', $talk));

        $this->get(route('showSermonWithDate', [
            'year' => '2026',
            'month' => '02',
            'sermon' => $talk->slug,
        ]))->assertRedirect(route('childrens-corner.show', $talk));
    }

    #[Test]
    public function header_navigation_shows_childrens_corner_link_to_authenticated_users(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/christ')
            ->assertStatus(200)
            ->assertSee('href="'.route('childrens-corner.index').'"', false);
    }

    #[Test]
    public function header_navigation_hides_childrens_corner_link_from_guests(): void
    {
        $this->get('/christ')
            ->assertStatus(200)
            ->assertDontSee('href="'.route('childrens-corner.index').'"', false);
    }

    #[Test]
    public function header_navigation_shows_childrens_corner_link_to_guests_when_public_release_is_enabled(): void
    {
        config(['sermons.childrens_talks.public' => true]);

        $this->get('/christ')
            ->assertStatus(200)
            ->assertSee('href="'.route('childrens-corner.index').'"', false);
    }
}
