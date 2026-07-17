<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PageArea;
use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Enums\SermonVideoVisibilityOverride;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Exposure transitions must evict warm public caches immediately: TTL
 * freshness is fine for ordinary edits, but a hidden or deleted media URL
 * must not keep being published from a stale cached model until the stale
 * window closes (issues O40/O41 in docs/issues/README.md).
 */
class PublicExposureCacheEvictionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function force_hiding_a_video_removes_its_url_from_the_warm_service_listing(): void
    {
        $sermon = Sermon::factory()->withVideo()->create([
            'service' => SermonService::Morning,
            'video_visibility_override' => SermonVideoVisibilityOverride::ForceShow,
        ]);

        $videoPath = (string) $sermon->video_file_path;

        $warmResponse = $this->get(route('sermons.service', 'morning'));
        $warmResponse->assertOk();
        $this->assertStringContainsString(
            $videoPath,
            (string) $warmResponse->getContent(),
            'expected the warmed listing to contain the public video URL',
        );

        $sermon->update(['video_visibility_override' => SermonVideoVisibilityOverride::ForceHide]);

        $hiddenResponse = $this->get(route('sermons.service', 'morning'));
        $hiddenResponse->assertOk();
        $this->assertStringNotContainsString(
            $videoPath,
            (string) $hiddenResponse->getContent(),
            'expected the force-hidden video URL to disappear from the warm listing immediately',
        );
    }

    #[Test]
    public function deleting_a_sermon_removes_it_from_the_warm_podcast_feed(): void
    {
        $sermon = Sermon::factory()->withAudio()->create([
            'service' => SermonService::Morning,
        ]);

        $warmResponse = $this->get(route('podcast.feed', ['service' => 'morning']));
        $warmResponse->assertOk();
        $this->assertStringContainsString(
            "sermon-{$sermon->id}",
            (string) $warmResponse->getContent(),
            'expected the warmed feed to contain the sermon',
        );

        $sermon->delete();

        $afterResponse = $this->get(route('podcast.feed', ['service' => 'morning']));
        $afterResponse->assertOk();
        $this->assertStringNotContainsString(
            "sermon-{$sermon->id}",
            (string) $afterResponse->getContent(),
            'expected the deleted sermon to disappear from the warm feed immediately',
        );
    }

    #[Test]
    public function reclassifying_a_sermon_removes_it_from_the_warm_podcast_feed(): void
    {
        // Reclassifying to a children's talk also dispatches the private-storage
        // mover, which would run synchronously against a non-existent fake file.
        Queue::fake();

        $sermon = Sermon::factory()->withAudio()->create([
            'service' => SermonService::Morning,
        ]);

        $this->get(route('podcast.feed', ['service' => 'morning']))
            ->assertOk()
            ->assertSee("sermon-{$sermon->id}");

        $sermon->update(['content_type' => SermonContentType::ChildrensTalk]);

        $this->assertStringNotContainsString(
            "sermon-{$sermon->id}",
            (string) $this->get(route('podcast.feed', ['service' => 'morning']))->getContent(),
            'expected the reclassified children\'s talk to disappear from the warm feed immediately',
        );
    }

    #[Test]
    public function restricting_a_page_removes_it_from_the_warm_navigation(): void
    {
        $page = Page::factory()->isNavigation()->create([
            'heading' => 'Navigation Eviction Probe',
            'area' => PageArea::Church,
            'admin' => 'no',
        ]);

        $this->get('/')->assertOk()->assertSee('Navigation Eviction Probe');

        $page->update(['admin' => 'yes']);

        $this->get('/')->assertOk()->assertDontSee('Navigation Eviction Probe');
    }

    #[Test]
    public function deleting_a_linked_page_removes_its_body_from_the_warm_meeting_read_model(): void
    {
        $page = Page::factory()->create([
            'slug' => 'eviction-probe-meeting',
            'area' => PageArea::Community,
            'admin' => 'no',
            'body' => 'DeletedPageBodyProbe content that must not outlive the page.',
            'markdown' => 'DeletedPageBodyProbe content that must not outlive the page.',
        ]);

        $meeting = Meeting::factory()->create([
            'slug' => 'eviction-probe-meeting',
            'page_id' => $page->id,
        ]);

        $warmResponse = $this->get(route('meetings.show', $meeting->slug));
        $warmResponse->assertOk();
        $this->assertStringContainsString(
            'DeletedPageBodyProbe',
            (string) $warmResponse->getContent(),
            'expected the warmed meeting page to contain the linked page body',
        );

        // The meetings.page_id FK is ON DELETE SET NULL, so by the time the
        // after-commit observer runs the relationship is already severed —
        // this exercises the deleting-time relation preload.
        $page->delete();

        $afterResponse = $this->get(route('meetings.show', $meeting->slug));
        $afterResponse->assertOk();
        $this->assertStringNotContainsString(
            'DeletedPageBodyProbe',
            (string) $afterResponse->getContent(),
            'expected the deleted page body to disappear from the meeting read model immediately',
        );
    }
}
