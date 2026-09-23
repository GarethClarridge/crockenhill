<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonVideoQualityStatus;
use App\Enums\TalkType;
use App\Livewire\Sermons\BrowseSermons;
use App\Models\Sermon;
use App\Models\User;
use App\Presenters\SermonViewPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The talks page: the sermon archive with a type switch, and the simple talk
 * template every non-sermon type renders on its dated URL.
 */
class TalksPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['church.talks.public_types' => ['sermon']]);
    }

    #[Test]
    public function guests_opening_a_members_only_type_are_redirected_to_login(): void
    {
        $this->get('/christ/talks?type=childrens_talk')->assertRedirect('/login');

        $talk = $this->childrensTalk(['slug' => 'guest-talk']);

        $this->get($this->datedUrl($talk))->assertRedirect('/login');
    }

    #[Test]
    public function unverified_users_are_treated_as_guests(): void
    {
        $this->actingAs(User::factory()->create(['email_verified_at' => null]));
        $talk = $this->childrensTalk(['slug' => 'unverified-talk']);

        $this->get('/christ/talks?type=childrens_talk')->assertRedirect(route('login'));
        $this->get($this->datedUrl($talk))->assertRedirect(route('login'));
    }

    #[Test]
    public function an_unknown_type_is_not_found(): void
    {
        $this->get('/christ/talks?type=sermonette')->assertNotFound();
    }

    #[Test]
    public function the_default_view_lists_only_sermons_with_an_unchanged_heading(): void
    {
        $this->actingAs(User::factory()->create());
        $this->childrensTalk(['title' => "Children's Talk One"]);
        Sermon::factory()->create(['title' => 'Sunday Sermon', 'content_type' => TalkType::Sermon]);

        $response = $this->get('/christ/talks');

        $response->assertOk();
        $response->assertSee('Sunday Sermon');
        $response->assertDontSee("Children's Talk One");
        $response->assertSee('<link rel="canonical" href="'.route('sermons.index').'"', false);
        $response->assertSee('<meta name="robots" content="max-image-preview:large">', false);
    }

    #[Test]
    public function a_selected_type_lists_only_that_type_with_its_own_heading(): void
    {
        $this->actingAs(User::factory()->create());
        $this->childrensTalk(['title' => "Children's Talk One"]);
        Sermon::factory()->create(['title' => 'Sunday Sermon', 'content_type' => TalkType::Sermon]);

        $response = $this->get('/christ/talks?type=childrens_talk');

        $response->assertOk();
        $response->assertSee("Children's Talk One");
        $response->assertDontSee('Sunday Sermon');
        $response->assertSee('Children&#039;s Talks | Crockenhill Baptist Church', false);
        $response->assertSee('<meta name="robots" content="noindex">', false);
        $response->assertSee('"@type": "ItemList"', false);
    }

    #[Test]
    public function the_type_switch_shows_only_types_the_viewer_may_access(): void
    {
        Livewire::test(BrowseSermons::class)
            ->assertDontSee('Children&#039;s Talks', false)
            ->assertViewHas('typeOptions', [TalkType::Sermon]);

        $this->actingAs(User::factory()->create());

        Livewire::test(BrowseSermons::class)
            ->assertViewHas('typeOptions', TalkType::cases());
    }

    #[Test]
    public function switching_type_clears_sermon_only_filters_and_hides_them(): void
    {
        $this->actingAs(User::factory()->create());
        $this->childrensTalk(['title' => 'Switched Talk']);

        Livewire::test(BrowseSermons::class)
            ->set('seriesFilter', 'Romans')
            ->call('selectType', TalkType::ChildrensTalk->value)
            ->assertSet('typeFilter', 'childrens_talk')
            ->assertSet('seriesFilter', null)
            ->assertSee('Switched Talk')
            ->assertDontSee('Filter sermons');
    }

    #[Test]
    public function a_guest_cannot_switch_to_a_members_only_type(): void
    {
        $this->childrensTalk(['title' => 'Hidden Talk']);

        Livewire::test(BrowseSermons::class)
            ->call('selectType', TalkType::ChildrensTalk->value)
            ->assertSet('typeFilter', 'sermon')
            ->assertDontSee('Hidden Talk');
    }

    #[Test]
    public function the_listing_paginates_a_type(): void
    {
        $this->actingAs(User::factory()->create());
        Sermon::factory()->count(25)->create(['content_type' => TalkType::ChildrensTalk]);

        Livewire::withQueryParams(['type' => 'childrens_talk'])
            ->test(BrowseSermons::class)
            ->assertViewHas('sermons', fn ($sermons): bool => $sermons->count() === 24 && $sermons->total() === 25);
    }

    #[Test]
    public function the_listing_renders_the_card_thumbnail_url(): void
    {
        $this->actingAs(User::factory()->create());
        $this->childrensTalk([
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'thumbnails/card-little-listeners-plain.jpg',
                'card_thumbnail_path' => 'thumbnails/card-little-listeners.jpg',
            ],
        ]);

        $response = $this->get('/christ/talks?type=childrens_talk');

        $response->assertOk();
        $response->assertSee('thumbnails/card-little-listeners-plain.jpg', false);
        $response->assertDontSee('/storage/private/', false);
    }

    #[Test]
    public function the_talk_template_renders_for_a_childrens_talk(): void
    {
        $this->actingAs(User::factory()->create());
        $talk = $this->childrensTalk([
            'title' => 'Little Listeners',
            'slug' => 'little-listeners',
            'audio_file_path' => 'sermons/audio/little-listeners.mp3',
            'video_file_path' => 'sermons/video/little-listeners.mp4',
            'show_summary' => true,
            'summary' => 'This should not appear on the simplified page.',
        ]);

        $response = $this->get($this->datedUrl($talk));

        $response->assertOk();
        $response->assertViewIs('sermons.talk');
        $response->assertSee('Little Listeners');
        $response->assertSee('Watch');
        $response->assertSee('Listen');
        $response->assertDontSee('Summary');
        $response->assertSee('Back to Children&#039;s Talks', false);
        $response->assertSee(route('sermons.index', ['type' => 'childrens_talk']), false);
        $response->assertSee('<meta name="robots" content="noindex">', false);

        $talk->load('preacherProfile');
        $speakerName = app(SermonViewPresenter::class)->displayPreacherName($talk);
        $response->assertSee("Little Listeners | {$speakerName} | Crockenhill Baptist Church");

        $response->assertSee('"@type": "Article"', false);
        $response->assertSee('"headline": "Little Listeners"', false);
    }

    #[Test]
    public function the_talk_template_serves_every_non_sermon_type(): void
    {
        config(['church.talks.public_types' => ['sermon', 'testimony']]);
        $testimony = Sermon::factory()->create([
            'title' => 'How I Came To Faith',
            'content_type' => TalkType::Testimony,
        ]);

        $this->get($this->datedUrl($testimony))
            ->assertOk()
            ->assertViewIs('sermons.talk')
            ->assertSee('Back to Testimonies');
    }

    #[Test]
    public function sermons_keep_the_rich_template(): void
    {
        $sermon = Sermon::factory()->create(['content_type' => TalkType::Sermon]);

        $this->get($this->datedUrl($sermon))
            ->assertOk()
            ->assertViewIs('sermons.sermon');
    }

    #[Test]
    public function the_talk_page_omits_a_rejected_video(): void
    {
        $this->actingAs(User::factory()->create());
        $talk = $this->childrensTalk([
            'audio_file_path' => 'sermons/audio/little-listeners.mp3',
            'video_file_path' => 'sermons/video/little-listeners.mp4',
            'video_quality_status' => SermonVideoQualityStatus::Rejected,
        ]);

        $response = $this->get($this->datedUrl($talk));

        $response->assertOk();
        $response->assertDontSee('Video available');
        $response->assertDontSee('<video', false);
        $response->assertSee('Listen');
    }

    #[Test]
    public function the_talk_page_links_media_assets_directly(): void
    {
        $this->actingAs(User::factory()->create());
        $talk = $this->childrensTalk([
            'audio_file_path' => 'sermons/audio/public-media-little-listeners.mp3',
            'video_file_path' => 'sermons/video/public-media-little-listeners.mp4',
            'thumbnail_file_path' => 'thumbnails/public-media-little-listeners.jpg',
        ]);

        $presented = app(SermonViewPresenter::class)->present($talk);
        $response = $this->get($this->datedUrl($talk));

        $response->assertOk();
        $response->assertSee($presented['audio_url'], false);
        $response->assertSee($presented['video_url'], false);
        $response->assertSee($presented['thumbnail_url'], false);
        $response->assertDontSee(route('sermons.audio', $talk), false);
        $response->assertDontSee('/storage/private/', false);
    }

    #[Test]
    public function guests_can_browse_a_type_once_it_is_public(): void
    {
        config(['church.talks.public_types' => ['sermon', 'childrens_talk']]);
        $talk = $this->childrensTalk(['title' => 'Public Little Listeners']);

        $this->get('/christ/talks?type=childrens_talk')
            ->assertOk()
            ->assertSee('Public Little Listeners')
            ->assertSee('<meta name="robots" content="max-image-preview:large">', false);

        $this->get($this->datedUrl($talk))
            ->assertOk()
            ->assertSee('Public Little Listeners');
    }

    #[Test]
    public function the_slug_only_route_redirects_every_type_to_its_dated_url(): void
    {
        $talk = $this->childrensTalk(['slug' => 'redirect-childrens-talk', 'date' => '2026-02-01']);

        $this->get(route('sermons.show', $talk))
            ->assertStatus(301)
            ->assertRedirect(route('sermons.show.dated', ['year' => '2026', 'month' => '02', 'sermon' => $talk->slug]));
    }

    #[Test]
    public function the_header_has_no_separate_childrens_corner_entry(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/christ')
            ->assertOk()
            ->assertDontSee("Children's Corner");
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function childrensTalk(array $attributes = []): Sermon
    {
        return Sermon::factory()->create(array_merge(['content_type' => TalkType::ChildrensTalk], $attributes));
    }

    private function datedUrl(Sermon $sermon): string
    {
        return route('sermons.show.dated', [
            'year' => $sermon->date->format('Y'),
            'month' => $sermon->date->format('m'),
            'sermon' => $sermon->slug,
        ]);
    }
}
