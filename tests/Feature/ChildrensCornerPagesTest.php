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
}
