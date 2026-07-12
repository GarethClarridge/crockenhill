<?php

declare(strict_types=1);

namespace Tests\Integration\Services\Monitoring;

use App\Enums\PageArea;
use App\Enums\SermonContentType;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Services\Monitoring\RouteCanaryRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RouteCanaryRegistryTest extends TestCase
{
    use RefreshDatabase;

    private RouteCanaryRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = app(RouteCanaryRegistry::class);
    }

    #[Test]
    public function it_always_returns_base_static_canaries(): void
    {
        $canaries = $this->registry->all();

        $urls = array_column($canaries, 'url');

        $this->assertContains('/', $urls);
        $this->assertContains('/christ/sermons', $urls);
        $this->assertContains('/sitemap.xml', $urls);
        $this->assertContains('/church/members', $urls);

        // Verify some properties of static canaries
        $home = collect($canaries)->firstWhere('url', '/');
        $this->assertSame(200, $home->expectedStatus);
        $this->assertSame(1, $home->hits);
        $this->assertSame('Crockenhill', $home->marker);

        $members = collect($canaries)->firstWhere('url', '/church/members');
        $this->assertSame(302, $members->expectedStatus);
        $this->assertSame('', $members->marker);
    }

    #[Test]
    public function it_includes_a_representative_public_page_with_slug(): void
    {
        // Eligible page
        $page = Page::factory()->create([
            'area' => PageArea::Church,
            'slug' => 'about-us',
            'admin' => 'no',
        ]);

        // Ineligible: admin only
        Page::factory()->create(['area' => PageArea::Church, 'slug' => 'admin-page', 'admin' => 'yes']);
        // Ineligible: Members area
        Page::factory()->create(['area' => PageArea::Members, 'slug' => 'members-area', 'admin' => 'no']);
        // Ineligible: Community area (collides with meetings)
        Page::factory()->create(['area' => PageArea::Community, 'slug' => 'community-page', 'admin' => 'no']);

        $canaries = $this->registry->all();
        $urls = array_column($canaries, 'url');

        $this->assertContains('/church/about-us', $urls);
        $this->assertNotContains('/church/admin-page', $urls);
        $this->assertNotContains('/members/members-area', $urls);
        $this->assertNotContains('/community/community-page', $urls);

        $pageCanary = collect($canaries)->firstWhere('url', '/church/about-us');
        $this->assertSame(2, $pageCanary->hits); // Detail routes hit twice for cache exercise
    }

    #[Test]
    public function it_includes_a_representative_publicly_accessible_meeting(): void
    {
        // Eligible meeting
        $meeting = Meeting::factory()->create(['slug' => 'sunday-service']);

        // Ineligible meeting: linked to private page
        $privatePage = Page::factory()->create(['area' => PageArea::Members, 'admin' => 'no']);
        Meeting::factory()->create(['slug' => 'private-meeting', 'page_id' => $privatePage->id]);

        // Ineligible meeting: linked to admin page
        $adminPage = Page::factory()->create(['area' => PageArea::Church, 'admin' => 'yes']);
        Meeting::factory()->create(['slug' => 'admin-meeting', 'page_id' => $adminPage->id]);

        $canaries = $this->registry->all();
        $urls = array_column($canaries, 'url');

        $this->assertContains('/community/sunday-service', $urls);
        $this->assertNotContains('/community/private-meeting', $urls);
        $this->assertNotContains('/community/admin-meeting', $urls);

        $meetingCanary = collect($canaries)->firstWhere('url', '/community/sunday-service');
        $this->assertSame(2, $meetingCanary->hits);
    }

    #[Test]
    public function it_includes_a_representative_sermon_with_dated_redirect_and_preacher_routes(): void
    {
        $preacher = Preacher::factory()->create(['slug' => 'john-owen']);
        $sermon = Sermon::factory()
            ->withPreacher($preacher)
            ->create([
                'slug' => 'the-glory-of-christ',
                'date' => '2024-05-19',
                'content_type' => SermonContentType::Sermon,
            ]);

        // Ineligible sermon: children's talk (depending on config, usually hidden from sitemap)
        config(['church.sermons.childrens_talks.public' => false]);
        Sermon::factory()->create([
            'slug' => 'kids-talk',
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        $canaries = $this->registry->all();
        $urls = array_column($canaries, 'url');

        // Dated canonical route
        $this->assertContains('/christ/sermons/2024/05/the-glory-of-christ', $urls);
        // Legacy slug-only redirect
        $this->assertContains('/christ/sermons/the-glory-of-christ', $urls);
        // Preacher route
        $this->assertContains('/christ/sermons/preachers/john-owen', $urls);

        $this->assertNotContains('/christ/sermons/kids-talk', $urls);

        $datedCanary = collect($canaries)->firstWhere('url', '/christ/sermons/2024/05/the-glory-of-christ');
        $this->assertSame(200, $datedCanary->expectedStatus);
        $this->assertSame(2, $datedCanary->hits);

        $legacyCanary = collect($canaries)->firstWhere('url', '/christ/sermons/the-glory-of-christ');
        $this->assertSame(301, $legacyCanary->expectedStatus);
        $this->assertSame(1, $legacyCanary->hits);
        $this->assertSame('', $legacyCanary->marker);

        $preacherCanary = collect($canaries)->firstWhere('url', '/christ/sermons/preachers/john-owen');
        $this->assertSame(200, $preacherCanary->expectedStatus);
        $this->assertSame(1, $preacherCanary->hits);
    }

    #[Test]
    public function it_omits_preacher_canary_when_chosen_sermon_has_no_preacher_profile(): void
    {
        // Sermon with no linked preacher profile
        Sermon::factory()->create([
            'slug' => 'no-profile-sermon',
            'preacher_id' => null,
            'content_type' => SermonContentType::Sermon,
        ]);

        $canaries = $this->registry->all();
        $urls = array_column($canaries, 'url');

        // Note: representativeSermon() picks latest by date.
        $this->assertContains('/christ/sermons/no-profile-sermon', $urls);
        $this->assertStringNotContainsString('/christ/sermons/preachers/', implode(' ', $urls));
    }

    #[Test]
    public function it_returns_only_static_canaries_when_database_is_empty(): void
    {
        // Clear potential records created in setup or other tests (though we use Transactions)
        Page::query()->delete();
        Meeting::query()->delete();
        Sermon::query()->delete();

        $canaries = $this->registry->all();

        $this->assertCount(4, $canaries);
        $urls = array_column($canaries, 'url');
        $this->assertSame(['/', '/christ/sermons', '/sitemap.xml', '/church/members'], $urls);
    }
}
