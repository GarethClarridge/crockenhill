<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\Sermon;
use App\Models\Song;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class PublicChurchServiceArchiveTest extends DuskTestCase
{
    use DatabaseTruncation;

    public function test_visitor_can_browse_from_the_archive_into_a_service_and_back(): void
    {
        $service = $this->serviceWithContent('2026-06-14', SermonService::Morning, 'Genesis 1:1-5');

        $sermon = Sermon::factory()->create([
            'title' => 'A Dusk Service Sermon',
            'slug' => 'a-dusk-service-sermon',
            'date' => $service->date,
            'service' => $service->service,
            'content_type' => SermonContentType::Sermon,
        ]);

        $this->browse(function (Browser $browser) use ($sermon): void {
            $browser->visit('/church/services')
                ->assertSee('14 June 2026')
                ->clickLink('View service')
                ->waitForLocation('/church/services/2026-06-14/morning')
                ->assertSee('Genesis 1:1-5')
                ->assertSee($sermon->title)
                ->clickLink('Back to services')
                ->waitForLocation('/church/services')
                ->assertSee('14 June 2026');
        });
    }

    public function test_year_and_service_filters_narrow_the_archive(): void
    {
        $this->serviceWithContent('2026-06-14', SermonService::Morning, 'Genesis 1:1-5');
        $this->serviceWithContent('2025-06-15', SermonService::Morning, 'Exodus 2:1-10');
        $this->serviceWithContent('2026-06-21', SermonService::Evening, 'Leviticus 3:1-5');

        $this->browse(function (Browser $browser): void {
            $browser->visit('/church/services')
                ->assertSee('14 June 2026')
                ->assertSee('15 June 2025')
                ->clickLink('2026')
                ->waitUntilMissingText('15 June 2025')
                ->assertQueryStringHas('year', '2026')
                ->assertSee('14 June 2026')
                ->clickLink('Evening')
                ->waitUntilMissingText('14 June 2026')
                ->assertQueryStringHas('service', 'evening')
                ->assertQueryStringHas('year', '2026')
                ->assertSee('21 June 2026');
        });
    }

    public function test_archive_is_keyboard_navigable(): void
    {
        $this->serviceWithContent('2026-06-14', SermonService::Morning, 'Genesis 1:1-5');

        $this->browse(function (Browser $browser): void {
            // Every route into a service must be a real anchor rather than a click
            // handler, so keyboard and screen-reader users can reach it, and the
            // filter group must be labelled for anyone navigating by landmark.
            $browser->visit('/church/services')
                ->assertPresent('a[href$="/church/services/2026-06-14/morning"]')
                ->assertSourceHas('aria-label="Service archive filters"')
                ->assertSourceHas('aria-current="page"')
                ->click('a[href$="/church/services/2026-06-14/morning"]')
                ->waitForLocation('/church/services/2026-06-14/morning')
                ->assertSourceHas('aria-label="Public service order"');
        });
    }

    private function serviceWithContent(string $date, SermonService $service, string $reading): ChurchService
    {
        $churchService = ChurchService::factory()->create([
            'date' => $date,
            'service' => $service,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'title' => $reading,
            'type' => 'bibles',
        ]);

        $song = Song::factory()->create();
        ChurchServiceItem::factory()->song()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'title' => $song->title,
            'song_id' => $song->id,
        ]);

        return $churchService;
    }
}
