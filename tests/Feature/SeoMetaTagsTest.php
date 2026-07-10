<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Wiring smoke tests for the shared <head> SEO components on non-sermon pages.
 *
 * Sermon-page meta tags are covered by SermonOpenGraphTest / SermonJsonLdTest;
 * the Page model's meta-description truncation/fallback logic is covered by
 * Tests\Integration\Models\PageSeoTest. This file proves the x-meta-tags and
 * x-schema.* components are wired into the homepage, the static section pages,
 * and dynamic Page-model pages, and that the analytics tag toggles on config.
 */
#[Group('dedicated')]
class SeoMetaTagsTest extends TestCase
{
    use RefreshDatabase;

    // Tests rely on seeded data (pages, sermons) for the homepage and section pages.
    protected $seed = true;

    #[Test]
    public function homepage_renders_meta_open_graph_twitter_and_canonical_tags(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('<link rel="canonical"', false);
        $response->assertSee('<meta name="description"', false);
        $response->assertSee('We are an independent evangelical Baptist church in Crockenhill, Kent', false);
        $response->assertSee('<meta property="og:title" content="Crockenhill Baptist Church">', false);
        $response->assertSee('<meta property="og:type" content="website">', false);
        $response->assertSee('<meta property="og:site_name" content="Crockenhill Baptist Church">', false);
        $response->assertSee('<meta property="og:image"', false);
        $response->assertSee('<meta name="twitter:card" content="summary_large_image">', false);
        $response->assertSee('<meta name="twitter:title"', false);
        $response->assertSee('<meta name="twitter:image"', false);
    }

    #[Test]
    public function homepage_renders_organization_and_website_schema(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('"@type": "Church"', false);
        $response->assertSee('"name": "Crockenhill Baptist Church"', false);
        $response->assertSee('"@id": "'.config('app.url').'/#organization"', false);
        $response->assertSee('"streetAddress": "Eynsford Road"', false);
        $response->assertSee('"postalCode": "BR8 8JS"', false);
        $response->assertSee('"telephone": "+44-1322-663995"', false);
        $response->assertSee('"openingHoursSpecification":', false);
        $response->assertSee('"@type": "WebSite"', false);
        $response->assertSee('"@id": "'.config('app.url').'/#website"', false);

        $logoSize = getimagesize(public_path('images/Primary.png'));
        if ($logoSize === false) {
            $this->fail('Primary logo image dimensions could not be read.');
        }

        $response->assertSee('"width": '.$logoSize[0], false);
        $response->assertSee('"height": '.$logoSize[1], false);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function sectionPageProvider(): array
    {
        return [
            'christ' => ['/christ', 'Christ | Crockenhill Baptist Church', 'Learn about Jesus Christ'],
            'church' => ['/church', 'Church | Crockenhill Baptist Church', 'Learn about Crockenhill Baptist Church'],
            'community' => ['/community', 'Community | Crockenhill Baptist Church', 'Join our community activities'],
        ];
    }

    #[Test]
    #[DataProvider('sectionPageProvider')]
    public function section_page_renders_meta_description_and_og_tags(string $path, string $ogTitle, string $descriptionFragment): void
    {
        $response = $this->get($path);

        $response->assertStatus(200);
        $response->assertSee('<meta name="description"', false);
        $response->assertSee($descriptionFragment, false);
        $response->assertSee('<meta property="og:title" content="'.$ogTitle.'">', false);
        $response->assertSee('<meta name="twitter:card"', false);
        $response->assertSee('<link rel="canonical"', false);
    }

    #[Test]
    public function dynamic_page_renders_meta_description_and_open_graph_tags(): void
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
        $response->assertSee('<meta name="description"', false);
        $response->assertSee('Test description for OG tags', false);
        $response->assertSee('<meta property="og:title"', false);
        $response->assertSee('Test OG Page', false);
        $response->assertSee('<meta property="og:type" content="website">', false);
        $response->assertSee('<meta property="og:url"', false);
    }

    #[Test]
    public function google_analytics_id_and_consent_banner_appear_when_configured(): void
    {
        config(['services.google_analytics.measurement_id' => 'G-TEST123456']);

        $response = $this->get('/');

        $response->assertStatus(200);
        // The bootstrap now lives in resources/js/analytics.js; the layout only
        // hands it the measurement ID. The gtag library is loaded from there.
        $response->assertSee('window.__gaId', false);
        $response->assertSee('G-TEST123456', false);
        // Consent banner (GA1) only renders when there is analytics to consent to.
        $response->assertSee('id="cookie-consent-heading"', false);
    }

    #[Test]
    public function google_analytics_id_and_consent_banner_are_absent_when_not_configured(): void
    {
        config(['services.google_analytics.measurement_id' => null]);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertDontSee('window.__gaId', false);
        $response->assertDontSee('id="cookie-consent-heading"', false);
    }
}
