<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Preacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Wiring smoke tests for page-type-specific Open Graph variations that have no
 * presenter equivalent: the preacher profile (og:type=profile) and the meeting
 * events page image metadata.
 *
 * The sermon image-alt text and twitter card-type derivation are covered at the
 * presenter / component level (SermonViewPresenterTest::image_alt_*, and the
 * x-meta-tags card-type logic exercised by SermonOpenGraphTest).
 */
class SeoMetadataImprovementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function preacher_page_has_profile_open_graph_metadata(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'John Owen', 'slug' => 'john-owen']);

        $response = $this->get("/christ/sermons/preachers/{$preacher->slug}");

        $response->assertStatus(200);
        $response->assertSee('property="og:type"', false);
        $response->assertSee('content="profile"', false);
        $response->assertSee('property="profile:username"', false);
        $response->assertSee('content="john-owen"', false);
    }

    #[Test]
    public function meeting_events_page_has_image_metadata(): void
    {
        $meeting = Meeting::factory()->create(['slug' => 'test-meeting']);

        $response = $this->get("/meetings/{$meeting->slug}/events");

        $response->assertStatus(200);
        $response->assertSee('property="og:image"', false);
        $response->assertSee('content="Events for ', false);
    }
}
