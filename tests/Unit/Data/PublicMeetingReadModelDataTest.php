<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\PublicMeetingReadModel;
use App\Models\Meeting;
use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicMeetingReadModelDataTest extends TestCase
{
    use RefreshDatabase;

    private function makeModel(): PublicMeetingReadModel
    {
        return new PublicMeetingReadModel(
            area: 'community',
            content: '<p>Meeting info</p>',
            description: 'Sunday Service',
            heading: 'Sunday Morning',
            headingpicture: 'images/sunday.jpg',
            headingpictureMobile: null,
            headingpictureTablet: null,
            metaDescription: 'Join us on Sunday',
            pageDescription: 'We meet every Sunday at 10:30am',
            photos: collect([]),
            slug: 'sunday-morning',
            upcomingEvents: collect([]),
        );
    }

    // ── Construction ──────────────────────────────────────────────────────────

    #[Test]
    public function it_stores_all_properties(): void
    {
        $model = $this->makeModel();

        $this->assertSame('community', $model->area);
        $this->assertSame('Sunday Morning', $model->heading);
        $this->assertSame('sunday-morning', $model->slug);
        $this->assertSame('We meet every Sunday at 10:30am', $model->pageDescription);
        $this->assertNull($model->headingpictureMobile);
        $this->assertInstanceOf(Collection::class, $model->upcomingEvents);
        $this->assertInstanceOf(Collection::class, $model->photos);
    }

    // ── toViewData ────────────────────────────────────────────────────────────

    #[Test]
    public function to_view_data_returns_all_keys(): void
    {
        $meeting = Meeting::factory()->create();
        $viewData = $this->makeModel()->toViewData(links: [], meeting: $meeting, pastEvents: collect());

        foreach (['area', 'content', 'description', 'heading', 'headingpicture', 'headingpictureMobile',
            'headingpictureTablet', 'links', 'meeting', 'metaDescription', 'page', 'pageDescription',
            'pastEvents', 'photos', 'slug', 'upcomingEvents'] as $key) {
            $this->assertArrayHasKey($key, $viewData, "Missing key: {$key}");
        }
    }

    #[Test]
    public function to_view_data_includes_meeting_model(): void
    {
        $meeting = Meeting::factory()->create();
        $viewData = $this->makeModel()->toViewData(links: [], meeting: $meeting, pastEvents: collect());

        $this->assertInstanceOf(Meeting::class, $viewData['meeting']);
        $this->assertSame($meeting->id, $viewData['meeting']->id);
    }

    #[Test]
    public function to_view_data_defaults_page_to_null(): void
    {
        $meeting = Meeting::factory()->create();
        $viewData = $this->makeModel()->toViewData(links: [], meeting: $meeting, pastEvents: collect());

        $this->assertNull($viewData['page']);
    }

    #[Test]
    public function to_view_data_includes_page_when_provided(): void
    {
        $meeting = Meeting::factory()->create();
        $page = Page::factory()->create(['slug' => 'sunday-morning']);

        $viewData = $this->makeModel()->toViewData(links: [], meeting: $meeting, pastEvents: collect(), page: $page);

        $this->assertInstanceOf(Page::class, $viewData['page']);
    }

    #[Test]
    public function to_view_data_wraps_array_links_in_collection(): void
    {
        $meeting = Meeting::factory()->create();
        $links = [
            ['area' => 'community', 'description' => 'Sunday', 'heading' => 'Sunday', 'image_url' => '', 'slug' => 'sunday', 'url' => '/community/sunday'],
        ];

        $viewData = $this->makeModel()->toViewData(links: $links, meeting: $meeting, pastEvents: collect());

        $this->assertInstanceOf(Collection::class, $viewData['links']);
        $this->assertCount(1, $viewData['links']);
    }

    #[Test]
    public function to_view_data_passes_through_collection_links(): void
    {
        $meeting = Meeting::factory()->create();
        $links = collect([
            ['area' => 'community', 'description' => 'Sunday', 'heading' => 'Sunday', 'image_url' => '', 'slug' => 'sunday', 'url' => '/sunday'],
        ]);

        $viewData = $this->makeModel()->toViewData(links: $links, meeting: $meeting, pastEvents: collect());

        $this->assertSame($links, $viewData['links']);
    }

    #[Test]
    public function to_view_data_passes_through_past_events_from_caller(): void
    {
        $upcomingEvents = collect([['date' => '2024-04-14', 'title' => 'Easter Service']]);
        $pastEvents = collect([['date' => '2024-03-17', 'title' => 'Palm Sunday']]);
        $photos = collect([['name' => 'church.jpg', 'thumbnail' => 'church-thumb.jpg', 'url' => '/images/church.jpg']]);

        $model = new PublicMeetingReadModel(
            area: 'community',
            content: '<p>Content</p>',
            description: 'Desc',
            heading: 'Heading',
            headingpicture: null,
            headingpictureMobile: null,
            headingpictureTablet: null,
            metaDescription: 'Meta',
            pageDescription: null,
            photos: $photos,
            slug: 'slug',
            upcomingEvents: $upcomingEvents,
        );

        $meeting = Meeting::factory()->create();
        $viewData = $model->toViewData(links: [], meeting: $meeting, pastEvents: $pastEvents);

        $this->assertSame($upcomingEvents, $viewData['upcomingEvents']);
        $this->assertSame($pastEvents, $viewData['pastEvents']);
        $this->assertSame($photos, $viewData['photos']);
    }
}
