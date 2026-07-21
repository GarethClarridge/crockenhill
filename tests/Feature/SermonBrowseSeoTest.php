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

        // Robust assertion targeting the content of the title tag
        $this->assertMatchesRegularExpression('/<title>[^<]*Sermons \| Crockenhill Baptist Church[^<]*<\/title>/i', $response->getContent());

        // Robust assertions for the description tag
        $response->assertSee('<meta name="description"', false);
        $response->assertSee('Explore the sermon archive at Crockenhill Baptist Church', false);

        // Robust assertions for the canonical link
        $response->assertSee('<link rel="canonical"', false);
        $response->assertSee('href="http://localhost/christ/sermons"', false);
    }

    public function test_filtered_archive_renders_dynamic_presenter_seo_in_the_head(): void
    {
        $response = $this->get('/christ/sermons?book=John&chapter=3');

        $response->assertStatus(200);

        // Robust assertion targeting the content of the title tag
        $this->assertMatchesRegularExpression('/<title>[^<]*John 3 \| Sermons \| Crockenhill Baptist Church[^<]*<\/title>/i', $response->getContent());

        // Robust assertions for the description tag
        $response->assertSee('<meta name="description"', false);
        $response->assertSee('Watch or listen to Bible-based sermons on John 3', false);

        // Robust assertions for the canonical link
        $response->assertSee('<link rel="canonical"', false);
        $response->assertSee('href="http://localhost/christ/sermons?book=John&amp;chapter=3"', false);
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

        // Robust assertion targeting the content of the title tag
        $this->assertMatchesRegularExpression('/<title>[^<]*The Glory of Christ \| John Owen \| Crockenhill Baptist Church[^<]*<\/title>/i', $response->getContent());

        // Robust assertions for the canonical link
        $response->assertSee('<link rel="canonical"', false);
        $response->assertSee('href="'.$canonicalUrl.'"', false);
    }
}
