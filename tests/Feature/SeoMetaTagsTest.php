<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('dedicated')]
class SeoMetaTagsTest extends TestCase
{
    use RefreshDatabase;

    // Tests rely on seeded data (pages, sermons)
    // Seeding on every test is slow but ensures data isolation
    protected $seed = true;

    #[Test]
    public function homepage_has_meta_description(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('<meta name="description"', false);
        $response->assertSee('We are an independent evangelical church in Crockenhill, Kent', false);
    }

    #[Test]
    public function homepage_has_canonical_url(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('<link rel="canonical"', false);
    }

    #[Test]
    public function homepage_has_open_graph_tags(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('<meta property="og:title" content="Crockenhill Baptist Church">', false);
        $response->assertSee('<meta property="og:type" content="website">', false);
        $response->assertSee('<meta property="og:url"', false);
        $response->assertSee('<meta property="og:site_name" content="Crockenhill Baptist Church">', false);
        $response->assertSee('<meta property="og:image"', false);
    }

    #[Test]
    public function homepage_has_twitter_card_tags(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('<meta name="twitter:card" content="summary_large_image">', false);
        $response->assertSee('<meta name="twitter:title"', false);
        $response->assertSee('<meta name="twitter:description"', false);
        $response->assertSee('<meta name="twitter:image"', false);
    }

    #[Test]
    public function homepage_has_organization_schema(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('"@type": "Church"', false);
        $response->assertSee('"name": "Crockenhill Baptist Church"', false);
        $response->assertSee('"@id": "'.config('app.url').'"', false);
        $response->assertSee('"streetAddress": "Eynsford Road"', false);
        $response->assertSee('"postalCode": "BR8 8JS"', false);
        $response->assertSee('"latitude": "51.38349261524606"', false);
        $response->assertSee('"longitude": "0.16404725602797054"', false);
        $response->assertSee('"telephone": "+44-1322-663995"', false);
    }

    #[Test]
    public function christ_page_has_meta_description_and_og_tags(): void
    {
        $response = $this->get('/christ');

        $response->assertStatus(200);
        $response->assertSee('<meta name="description"', false);
        $response->assertSee('Learn about Jesus Christ', false);
        $response->assertSee('<meta property="og:title" content="Christ | Crockenhill Baptist Church">', false);
        $response->assertSee('<meta name="twitter:card"', false);
    }

    #[Test]
    public function church_page_has_meta_description_and_og_tags(): void
    {
        $response = $this->get('/church');

        $response->assertStatus(200);
        $response->assertSee('<meta name="description"', false);
        $response->assertSee('Learn about Crockenhill Baptist Church', false);
        $response->assertSee('<meta property="og:title" content="Church | Crockenhill Baptist Church">', false);
        $response->assertSee('<meta name="twitter:card"', false);
    }

    #[Test]
    public function community_page_has_meta_description_and_og_tags(): void
    {
        $response = $this->get('/community');

        $response->assertStatus(200);
        $response->assertSee('<meta name="description"', false);
        $response->assertSee('Join our community activities', false);
        $response->assertSee('<meta property="og:title" content="Community | Crockenhill Baptist Church">', false);
        $response->assertSee('<meta name="twitter:card"', false);
    }

    #[Test]
    public function dynamic_page_has_meta_description(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-page',
            'heading' => 'Test Page',
            'description' => 'This is a test page description that should appear as the meta description in the HTML head section.',
            'area' => 'church',
            'body' => 'Test body content',
        ]);

        $response = $this->get($page->route);

        $response->assertStatus(200);
        $response->assertSee('<meta name="description"', false);
        // Meta description should be truncated to 155 characters
        $response->assertSee('This is a test page description that should appear as the meta description in the HTML head section.', false);
    }

    #[Test]
    public function dynamic_page_has_open_graph_tags(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-og-page',
            'heading' => 'Test OG Page',
            'description' => 'Test description for OG tags',
            'area' => 'church',
            'body' => 'Test body content',
        ]);

        $response = $this->get($page->route);

        $response->assertStatus(200);
        $response->assertSee('<meta property="og:title"', false);
        $response->assertSee('Test OG Page', false);
        $response->assertSee('<meta property="og:type" content="website">', false);
        $response->assertSee('<meta property="og:url"', false);
    }

    #[Test]
    public function sermon_page_has_meta_description(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon Title',
            'slug' => 'test-sermon-title',
            'preacher' => 'John Smith',
            'summary' => 'This is a test sermon summary that will be used to generate the meta description automatically.',
            'date' => '2024-01-15',
        ]);

        $response = $this->followingRedirects()->get(route('sermons.show', $sermon->slug));

        $response->assertStatus(200);
        $response->assertSee('<meta name="description"', false);
        // Should contain title, preacher, and part of summary
        $response->assertSee('Test Sermon Title', false);
    }

    #[Test]
    public function sermon_page_has_open_graph_tags(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'OG Test Sermon',
            'slug' => 'og-test-sermon',
            'preacher' => 'Jane Doe',
            'summary' => 'Test summary for OG validation',
            'date' => '2024-01-15',
        ]);

        $response = $this->followingRedirects()->get(route('sermons.show', $sermon->slug));

        $response->assertStatus(200);
        $response->assertSee('<meta property="og:title"', false);
        $response->assertSee('OG Test Sermon', false);
        $response->assertSee('<meta property="og:type" content="article">', false);
        $response->assertSee('<meta property="og:url"', false);
    }

    #[Test]
    public function all_pages_have_canonical_url(): void
    {
        $pages = [
            '/',
            '/christ',
            '/church',
            '/community',
        ];

        foreach ($pages as $page) {
            $response = $this->get($page);
            $response->assertStatus(200);
            $response->assertSee('<link rel="canonical"', false);
        }
    }

    #[Test]
    public function google_analytics_tag_appears_when_configured(): void
    {
        config(['services.google_analytics.measurement_id' => 'G-TEST123456']);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('https://www.googletagmanager.com/gtag/js?id=G-TEST123456', false);
        $response->assertSee('gtag(\'config\', \'G-TEST123456\');', false);
    }

    #[Test]
    public function google_analytics_tag_does_not_appear_when_not_configured(): void
    {
        config(['services.google_analytics.measurement_id' => null]);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertDontSee('googletagmanager.com/gtag/js', false);
        $response->assertDontSee('gtag(', false);
    }

    #[Test]
    public function meta_descriptions_are_within_155_character_limit(): void
    {
        // Create a page with a very long description
        $page = Page::factory()->create([
            'slug' => 'long-description',
            'heading' => 'Test Page',
            'description' => str_repeat('This is a very long description that exceeds the recommended 155 character limit for meta descriptions. ', 5),
            'area' => 'church',
            'body' => 'Test body',
        ]);

        // The accessor should truncate it (allowing for ... ellipsis = 158 max)
        $this->assertLessThanOrEqual(158, strlen($page->meta_description));
    }
}
