<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\PageArea;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ListRouteCanariesCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_always_emits_the_static_route_canaries(): void
    {
        Artisan::call('monitoring:canaries');
        $output = Artisan::output();

        // url <TAB> expected status <TAB> hits <TAB> body marker
        $this->assertStringContainsString("/\t200\t1\tCrockenhill", $output);
        $this->assertStringContainsString("/christ/sermons\t200\t1\tCrockenhill", $output);
        $this->assertStringContainsString("/sitemap.xml\t200\t1\t<urlset", $output);
        // The auth guard must redirect guests: status only, no body marker.
        $this->assertStringContainsString("/church/members\t302\t1\t", $output);
    }

    #[Test]
    public function it_resolves_cached_detail_routes_from_real_records_and_requests_two_hits(): void
    {
        $page = Page::factory()->create([
            'area' => PageArea::Christ->value,
            'slug' => 'what-we-believe',
            'admin' => 'no',
        ]);

        $meeting = Meeting::factory()->create([
            'slug' => 'sunday-mornings',
            'page_id' => null,
        ]);

        $preacher = Preacher::factory()->create(['slug' => 'john-smith']);
        $sermon = Sermon::factory()->withPreacher($preacher)->create([
            'slug' => 'grace-abounding',
            'date' => now(),
        ]);

        Artisan::call('monitoring:canaries');
        $output = Artisan::output();

        // Cached read-model routes are hit twice to exercise the serialized read-back.
        $this->assertStringContainsString("/christ/{$page->slug}\t200\t2\tCrockenhill", $output);
        $this->assertStringContainsString("/community/{$meeting->slug}\t200\t2\tCrockenhill", $output);
        // The slug-only sermon route 301-redirects to the canonical dated URL, so the
        // render check targets the dated URL and a separate canary guards the redirect.
        $datedPath = $sermon->date->format('Y/m')."/{$sermon->slug}";
        $this->assertStringContainsString("/christ/sermons/{$datedPath}\t200\t2\tCrockenhill", $output);
        $this->assertStringContainsString("/christ/sermons/{$sermon->slug}\t301\t1\t", $output);
        // The preacher is taken from the chosen sermon, guaranteeing a visible page.
        $this->assertStringContainsString("/christ/sermons/preachers/{$preacher->slug}\t200\t1\tCrockenhill", $output);
    }

    #[Test]
    public function it_omits_detail_canaries_when_no_eligible_records_exist(): void
    {
        Artisan::call('monitoring:canaries');
        $output = Artisan::output();

        // Static canaries still emit, but nothing depends on absent data.
        $this->assertStringContainsString("/\t200\t1\tCrockenhill", $output);
        $this->assertStringNotContainsString('/community/', $output);
        $this->assertStringNotContainsString('/christ/sermons/preachers/', $output);
    }

    #[Test]
    public function it_skips_members_and_community_pages_for_the_cms_canary(): void
    {
        // Members pages need auth; community-area slugs collide with the meeting route.
        Page::factory()->create([
            'area' => PageArea::Members->value,
            'slug' => 'members-only',
            'admin' => 'no',
        ]);
        Page::factory()->create([
            'area' => PageArea::Community->value,
            'slug' => 'a-group',
            'admin' => 'no',
        ]);

        Artisan::call('monitoring:canaries');
        $output = Artisan::output();

        $this->assertStringNotContainsString('/members/members-only', $output);
        $this->assertStringNotContainsString('/community/a-group', $output);
    }
}
