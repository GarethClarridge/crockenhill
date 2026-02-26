<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SermonThumbnailUXTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_sermon_card_renders_thumbnail_when_available(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Sermon with Thumbnail',
            'slug' => 'sermon-with-thumbnail',
            'thumbnail_file_path' => 'thumbnails/test.jpg',
            'date' => '2026-02-19',
        ]);

        // Mock the file existence as required by hasThumbnail()
        Storage::disk('public')->put('thumbnails/test.jpg', 'fake content');

        $response = $this->get('/christ/sermons/all');
        $response->assertStatus(200);
        $response->assertSee('/christ/sermons/sermon-with-thumbnail/thumbnail');
        $response->assertSee('alt="Sermon: Sermon with Thumbnail"');
    }

    public function test_sermon_card_renders_livestream_badge(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Livestream Sermon',
            'source_type' => 'livestream',
            'date' => '2026-02-19',
        ]);

        $response = $this->get('/christ/sermons/all');
        $response->assertStatus(200);
        $response->assertSee('Livestream');
    }

    public function test_sermon_page_renders_thumbnail_when_no_video(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Sermon Page Thumbnail',
            'slug' => 'sermon-page-thumbnail',
            'thumbnail_file_path' => 'thumbnails/page.jpg',
            'video_file_path' => null,
            'date' => '2026-02-19',
        ]);

        // Mock the file existence
        Storage::disk('public')->put('thumbnails/page.jpg', 'fake content');

        $response = $this->get("/christ/sermons/{$sermon->slug}");
        $response->assertStatus(200);
        $response->assertSee('/christ/sermons/sermon-page-thumbnail/thumbnail');
    }
}
