<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Wiring smoke tests for the sermon-show Open Graph / Twitter Card block.
 *
 * The *values* these tags carry (title-with-preacher suffix, image alt text,
 * meta description variants) are asserted at the presenter level in
 * Tests\Integration\Presenters\SermonViewPresenterTest. These two tests only
 * prove the meta-tags component is wired into the sermon page and emits the
 * expected tag structure for the with-thumbnail and no-thumbnail cases.
 */
class SermonOpenGraphTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sermon_page_emits_open_graph_and_twitter_tags_with_thumbnail(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('sermons/thumbnails/test-thumbnail.jpg', 'fake-thumbnail-content');

        $sermon = Sermon::factory()->withAudio()->create([
            'title' => 'Test Sermon Title',
            'preacher' => 'John Smith',
            'date' => '2024-01-15',
            'thumbnail_file_path' => 'sermons/thumbnails/test-thumbnail.jpg',
            'thumbnail_generated_at' => now(),
        ]);

        $response = $this->get("/christ/sermons/{$sermon->date->year}/{$sermon->date->format('m')}/{$sermon->slug}");

        $response->assertStatus(200);
        $response->assertSee('property="og:title"', false);
        $response->assertSee('content="Test Sermon Title | John Smith | Crockenhill Baptist Church"', false);
        $response->assertSee('property="og:type"', false);
        $response->assertSee('content="article"', false);
        $response->assertSee('property="og:site_name"', false);
        $response->assertSee('content="Crockenhill Baptist Church"', false);
        $response->assertSee('property="og:image"', false);
        $response->assertSee('property="og:image:alt"', false);
        $response->assertSee('content="Sermon: Test Sermon Title by John Smith"', false);
        $response->assertSee('property="og:audio"', false);
        $response->assertSee('name="twitter:card"', false);
        $response->assertSee('content="summary_large_image"', false);
        $response->assertSee('name="twitter:title"', false);
    }

    #[Test]
    public function sermon_page_falls_back_to_site_image_when_no_thumbnail(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon Without Thumbnail',
            'preacher' => 'Jane Doe',
            'date' => '2024-02-20',
            'thumbnail_file_path' => null,
        ]);

        $response = $this->get("/christ/sermons/{$sermon->date->year}/{$sermon->date->format('m')}/{$sermon->slug}");

        $response->assertStatus(200);
        $response->assertSee('property="og:image"', false);
        $this->assertStringContainsString(
            'Primary.png',
            (string) $response->getContent(),
            'Should fall back to the site-wide image when no thumbnail is available.',
        );
    }
}
