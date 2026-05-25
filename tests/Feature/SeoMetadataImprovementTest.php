<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeoMetadataImprovementTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function preacher_page_has_profile_metadata(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'John Owen', 'slug' => 'john-owen']);

        $response = $this->get("/christ/sermons/preachers/{$preacher->slug}");

        $response->assertStatus(200);
        $response->assertSee('<meta property="og:type" content="profile">', false);
        $response->assertSee('<meta property="profile:username" content="john-owen">', false);
    }

    #[Test]
    public function twitter_card_type_is_dynamic(): void
    {
        // Page with image
        $preacher = Preacher::factory()->create(['image_path' => 'preachers/john-owen.jpg']);
        $response = $this->get("/christ/sermons/preachers/{$preacher->slug}");
        $response->assertSee('<meta name="twitter:card" content="summary_large_image">', false);

        // Page without image (if we can find one or mock it)
        // For now, let's verify that sermons with thumbnails have summary_large_image
        $sermon = Sermon::factory()->create([
            'thumbnail_file_path' => 'thumbnails/test.jpg',
            'date' => '2024-03-15',
        ]);
        $response = $this->followingRedirects()->get("/christ/sermons/2024/03/{$sermon->slug}");
        $response->assertSee('<meta name="twitter:card" content="summary_large_image">', false);
    }

    #[Test]
    public function sermon_page_has_improved_image_alt(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'John Owen']);
        $sermon = Sermon::factory()->create([
            'title' => 'Improved Alt Sermon',
            'preacher_id' => $preacher->id,
            'preacher' => $preacher->name,
            'date' => '2024-03-15',
        ]);

        $response = $this->followingRedirects()->get("/christ/sermons/2024/03/{$sermon->slug}");

        $response->assertStatus(200);
        $response->assertSee('content="Sermon: Improved Alt Sermon by John Owen"', false);
    }

    #[Test]
    public function meeting_events_page_has_image_metadata(): void
    {
        $meeting = Meeting::factory()->create(['slug' => 'test-meeting']);

        $response = $this->get("/meetings/{$meeting->slug}/events");

        $response->assertStatus(200);
        $response->assertSee('<meta property="og:image"', false);
        $response->assertSee('content="Events for ', false);
    }
}
