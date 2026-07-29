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

        // Assert title tag is present with correct content, allowing whitespace variation
        $this->assertMatchesRegularExpression(
            '/<title>\s*Sermons \| Crockenhill Baptist Church\s*<\/title>/i',
            $response->getContent()
        );

        // Assert meta description is present in an attribute-order-independent manner
        $this->assertMatchesRegularExpression(
            '/<meta\s+(?=[^>]*name="description")(?=[^>]*content="Explore the sermon archive at Crockenhill Baptist Church\. Watch or listen to Bible teaching from our Sunday services, filtered by scripture, preacher, or series\.")/i',
            $response->getContent()
        );

        // Assert canonical link is present in an attribute-order-independent manner
        $this->assertMatchesRegularExpression(
            '/<link\s+(?=[^>]*rel="canonical")(?=[^>]*href="http:\/\/localhost\/christ\/sermons")/i',
            $response->getContent()
        );
    }

    public function test_filtered_archive_renders_dynamic_presenter_seo_in_the_head(): void
    {
        $response = $this->get('/christ/sermons?book=John&chapter=3');

        $response->assertStatus(200);

        // Assert title tag is present with correct content, allowing whitespace variation
        $this->assertMatchesRegularExpression(
            '/<title>\s*John 3 \| Sermons \| Crockenhill Baptist Church\s*<\/title>/i',
            $response->getContent()
        );

        // Assert meta description is present in an attribute-order-independent manner
        $this->assertMatchesRegularExpression(
            '/<meta\s+(?=[^>]*name="description")(?=[^>]*content="Watch or listen to Bible-based sermons on John 3 from Crockenhill Baptist Church\. Explore recent teaching from our morning and evening services\.")/i',
            $response->getContent()
        );

        // Assert canonical link is present, tolerating either escaped or raw ampersands
        $this->assertMatchesRegularExpression(
            '/<link\s+(?=[^>]*rel="canonical")(?=[^>]*href="http:\/\/localhost\/christ\/sermons\?book=John(&amp;|&)chapter=3")/i',
            $response->getContent()
        );
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

        // Assert title tag is present with correct content, allowing whitespace variation
        $this->assertMatchesRegularExpression(
            '/<title>\s*The Glory of Christ \| John Owen \| Crockenhill Baptist Church\s*<\/title>/i',
            $response->getContent()
        );

        // Assert canonical link is present in an attribute-order-independent manner
        $this->assertMatchesRegularExpression(
            '/<link\s+(?=[^>]*rel="canonical")(?=[^>]*href="'.preg_quote($canonicalUrl, '/').'")/i',
            $response->getContent()
        );
    }
}
