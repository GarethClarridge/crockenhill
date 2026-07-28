<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wiring smoke tests for sermon archive <head> meta tags.
 *
 * The archive title/description/canonical variant matrix (book, chapter,
 * preacher, series, pagination) is exhaustively covered at the presenter level
 * in Tests\Integration\Presenters\SermonArchiveSeoPresenterTest. These tests
 * only prove the presenter output reaches the rendered <head>: one unfiltered
 * archive case and one filtered case (which also exercises the canonical query
 * string and HTML-escaping through the Blade layer).
 */
class SermonBrowseSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_unfiltered_archive_renders_presenter_seo_in_the_head(): void
    {
        $response = $this->get('/christ/sermons');

        $response->assertStatus(200);
        $this->assertHasTitle($response->getContent(), 'Sermons | Crockenhill Baptist Church');
        $this->assertHasMetaDescription($response->getContent(), 'Explore the sermon archive at Crockenhill Baptist Church. Watch or listen to Bible teaching from our Sunday services, filtered by scripture, preacher, or series.');
        $this->assertHasCanonicalLink($response->getContent(), 'http://localhost/christ/sermons');
    }

    public function test_filtered_archive_renders_dynamic_presenter_seo_in_the_head(): void
    {
        $response = $this->get('/christ/sermons?book=John&chapter=3');

        $response->assertStatus(200);
        $this->assertHasTitle($response->getContent(), 'John 3 | Sermons | Crockenhill Baptist Church');
        $this->assertHasMetaDescription($response->getContent(), 'Watch or listen to Bible-based sermons on John 3 from Crockenhill Baptist Church. Explore recent teaching from our morning and evening services.');
        $this->assertHasCanonicalLink($response->getContent(), 'http://localhost/christ/sermons?book=John&chapter=3');
    }

    public function test_individual_sermon_page_renders_canonical_and_title_in_the_head(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'The Glory of Christ',
            'preacher' => 'John Owen',
            'slug' => 'the-glory-of-christ',
            'date' => '2024-03-20',
        ]);

        $canonicalUrl = url('/christ/sermons/2024/03/the-glory-of-christ');
        $response = $this->get($canonicalUrl);

        $response->assertStatus(200);
        $this->assertHasTitle($response->getContent(), 'The Glory of Christ | John Owen | Crockenhill Baptist Church');
        $this->assertHasCanonicalLink($response->getContent(), $canonicalUrl);
    }

    private function assertHasTitle(string $content, string $expectedTitle): void
    {
        $escapedTitle = preg_quote($expectedTitle, '/');
        $pattern = '/<title>\s*'.$escapedTitle.'\s*<\/title>/i';
        $this->assertMatchesRegularExpression($pattern, $content, 'Page is missing the correct title.');
    }

    private function assertHasMetaDescription(string $content, string $expectedDescription): void
    {
        $escapedDescription = preg_quote($expectedDescription, '/');
        $pattern = '/<meta\b(?=[^>]*?\bname\s*=\s*["\']description["\'])(?=[^>]*?\bcontent\s*=\s*["\']'.$escapedDescription.'["\'])[^>]*>/i';
        $this->assertMatchesRegularExpression($pattern, $content, 'Page is missing the correct meta description.');
    }

    private function assertHasCanonicalLink(string $content, string $expectedUrl): void
    {
        $escapedUrl = preg_quote($expectedUrl, '/');
        $escapedUrlWithAmp = str_replace('&', '(?:&|&amp;|%26)', $escapedUrl);
        $pattern = '/<link\b(?=[^>]*?\brel\s*=\s*["\']canonical["\'])(?=[^>]*?\bhref\s*=\s*["\']'.$escapedUrlWithAmp.'["\'])[^>]*>/i';
        $this->assertMatchesRegularExpression($pattern, $content, 'Page is missing the correct canonical link.');
    }
}
