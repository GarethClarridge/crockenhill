<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonOpenGraphTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test user
        $this->user = User::factory()->create();
    }

    #[Test]
    public function sermon_page_includes_open_graph_meta_tags_with_thumbnail()
    {
        // Create a sermon with thumbnail
        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon Title',
            'preacher' => 'John Smith',
            'date' => '2024-01-15',
            'reference' => 'John 3:16',
            'series' => 'Gospel Series',
            'thumbnail_file_path' => 'sermons/thumbnails/test-thumbnail.jpg',
            'thumbnail_generated_at' => now(),
        ]);

        // Mock the thumbnail file existence
        Storage::fake('public');
        Storage::disk('public')->put('sermons/thumbnails/test-thumbnail.jpg', 'fake-thumbnail-content');

        $response = $this->get("/christ/sermons/{$sermon->date->year}/{$sermon->date->format('m')}/{$sermon->slug}");

        $response->assertStatus(200);

        // Check Open Graph meta tags
        $response->assertSee('<meta property="og:title" content="Test Sermon Title | John Smith | Crockenhill Baptist Church">', false);
        $response->assertSee('<meta property="og:type" content="article">', false);
        $response->assertSee('<meta property="og:site_name" content="Crockenhill Baptist Church">', false);
        $response->assertSee('<meta property="og:image"', false);
        $response->assertSee('<meta property="og:image:width"', false);
        $response->assertSee('<meta property="og:image:height"', false);
        $response->assertSee('<meta property="og:image:alt" content="Sermon: Test Sermon Title">', false);
        $response->assertSee('<meta property="og:audio"', false);

        // Check Twitter Card meta tags
        $response->assertSee('<meta name="twitter:card" content="summary_large_image">', false);
        $response->assertSee('<meta name="twitter:title" content="Test Sermon Title | John Smith | Crockenhill Baptist Church">', false);

        // Verify the page loads successfully with Open Graph tags
        $this->assertTrue(true, 'Open Graph meta tags are successfully implemented');
    }

    #[Test]
    public function sermon_page_includes_fallback_image_when_no_thumbnail()
    {
        // Create a sermon without thumbnail
        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon Without Thumbnail',
            'preacher' => 'Jane Doe',
            'date' => '2024-02-20',
            'thumbnail_file_path' => null,
        ]);

        $response = $this->get("/christ/sermons/{$sermon->date->year}/{$sermon->date->format('m')}/{$sermon->slug}");

        $response->assertStatus(200);

        // Should still have Open Graph meta tags with fallback image
        $response->assertSee('<meta property="og:title"', false);
        $response->assertSee('<meta property="og:description"', false);
        $response->assertSee('<meta property="og:image"', false);

        // Should use fallback image (either default thumbnail or Primary.png)
        $content = $response->getContent();
        $this->assertTrue(
            str_contains($content, 'default-sermon-thumbnail.jpg') ||
            str_contains($content, 'Primary.png'),
            'Should include fallback image when no thumbnail is available'
        );
    }

    #[Test]
    public function sermon_page_handles_missing_optional_fields_gracefully()
    {
        // Create a minimal sermon
        $sermon = Sermon::factory()->create([
            'title' => 'Minimal Sermon',
            'preacher' => 'Test Preacher',
            'date' => '2024-03-10',
            'reference' => null,
            'series' => null,
            'thumbnail_file_path' => null,
        ]);

        $response = $this->get("/christ/sermons/{$sermon->date->year}/{$sermon->date->format('m')}/{$sermon->slug}");

        $response->assertStatus(200);

        // Should still have basic Open Graph meta tags with preacher name in title
        $response->assertSee('<meta property="og:title" content="Minimal Sermon | Test Preacher | Crockenhill Baptist Church">', false);

        // Should not include series or reference in description when not present
        $content = $response->getContent();
        $this->assertStringNotContainsString('Part of the', $content);
        $this->assertStringNotContainsString(' - null', $content);
    }

    #[Test]
    public function sermon_page_includes_basic_meta_tags()
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Basic Meta Test',
            'preacher' => 'Test Preacher',
            'date' => '2024-04-01',
            'thumbnail_file_path' => 'sermons/thumbnails/basic-test.jpg',
        ]);

        // Mock the thumbnail file existence
        Storage::fake('public');
        Storage::disk('public')->put('sermons/thumbnails/basic-test.jpg', 'fake-content');

        $response = $this->get("/christ/sermons/{$sermon->date->year}/{$sermon->date->format('m')}/{$sermon->slug}");

        $response->assertStatus(200);

        // Check that basic Open Graph meta tags are present
        $content = $response->getContent();

        // Should have basic Open Graph tags with preacher in title
        $this->assertStringContainsString('<meta property="og:title" content="Basic Meta Test | Test Preacher | Crockenhill Baptist Church">', $content);
        $this->assertStringContainsString('<meta property="og:description"', $content);
        $this->assertStringContainsString('<meta property="og:image"', $content);
    }
}
