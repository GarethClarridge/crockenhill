<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonContentType;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Presenters\SermonViewPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonArchiveImageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function index_shows_preacher_image_when_filtered_by_preacher(): void
    {
        $preacher = Preacher::factory()->create([
            'name' => 'Charles Spurgeon',
            'image_path' => 'preachers/spurgeon.jpg',
        ]);

        $response = $this->get('/christ/sermons?preacher='.$preacher->id);

        $response->assertStatus(200);
        $response->assertSee('property="og:image" content="'.$preacher->profile_image_url.'"', false);
        $response->assertSee('property="og:image:alt" content="Preacher: Charles Spurgeon"', false);
    }

    #[Test]
    public function index_shows_sermon_thumbnail_when_filtered_by_series(): void
    {
        $sermon = Sermon::factory()->create([
            'series' => 'Life of David',
            'thumbnail_file_path' => 'sermons/david.jpg',
            'content_type' => SermonContentType::Sermon,
            'date' => now(),
        ]);

        $response = $this->get('/christ/sermons?series=Life+of+David');

        $response->assertStatus(200);
        $image = app(SermonViewPresenter::class)->thumbnailUrl($sermon);
        $response->assertSee('property="og:image" content="'.$image.'"', false);
        $response->assertSee('property="og:image:alt" content="Sermon Series: Life of David"', false);
    }
}
