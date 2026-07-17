<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonService;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeoRegressionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sermon_page_has_a_valid_meta_description_and_it_is_not_empty(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon',
            'date' => now(),
            'slug' => 'test-sermon',
        ]);

        $response = $this->get(route('sermons.show.dated', [
            'year' => $sermon->date->format('Y'),
            'month' => $sermon->date->format('m'),
            'sermon' => $sermon->slug,
        ]));

        $response->assertOk();

        $this->assertMatchesRegularExpression(
            '/<meta name="description" content="[^"]+">/',
            (string) $response->getContent(),
            'Meta description should not be empty.'
        );
    }

    #[Test]
    public function sermon_index_page_has_descriptive_meta_tags_and_podcast_discovery_links(): void
    {
        Sermon::factory()->create([
            'title' => 'Morning Sermon',
            'service' => SermonService::Morning,
            'date' => '2026-03-01',
        ]);
        Sermon::factory()->create([
            'title' => 'Evening Sermon',
            'service' => SermonService::Evening,
            'date' => '2026-03-01',
        ]);

        $description = 'Explore the sermon archive at Crockenhill Baptist Church. Watch or listen to Bible teaching from our Sunday services, filtered by scripture, preacher, or series.';
        $response = $this->get(route('sermons.index'));

        $response->assertOk();
        $response->assertSee('Sermons | Crockenhill Baptist Church');
        $response->assertSee('name="description"', false);
        $response->assertSee('content="'.$description.'"', false);
        $response->assertSee('type="application/rss+xml"', false);
        $response->assertSee('title="Sunday Morning Sermons"', false);
        $response->assertSee('href="'.route('podcast.feed', 'morning').'"', false);
        $response->assertSee('title="Sunday Evening Sermons"', false);
        $response->assertSee('href="'.route('podcast.feed', 'evening').'"', false);
    }

    #[Test]
    public function sermon_service_pages_have_service_specific_meta_tags_and_podcast_discovery_links(): void
    {
        Sermon::factory()->create([
            'title' => 'Morning Sermon',
            'service' => SermonService::Morning,
            'date' => '2026-03-02',
        ]);
        Sermon::factory()->create([
            'title' => 'Evening Sermon',
            'service' => SermonService::Evening,
            'date' => '2026-03-02',
        ]);

        foreach ([
            'morning' => 'Sunday Morning',
            'evening' => 'Sunday Evening',
        ] as $service => $label) {
            $description = "Listen to recent {$label} sermons from Crockenhill Baptist Church.";
            $response = $this->get(route('sermons.service', $service));

            $response->assertOk();
            $response->assertSee("{$label} Services | Crockenhill Baptist Church");
            $response->assertSee('name="description"', false);
            $response->assertSee('content="'.$description.'"', false);
            $response->assertSee('type="application/rss+xml"', false);
            $response->assertSee('title="'.$label.' Services Podcast"', false);
            $response->assertSee('href="'.route('podcast.feed', $service).'"', false);
        }
    }

    #[Test]
    public function other_service_page_renders_podcast_discovery_links(): void
    {
        Sermon::factory()->create([
            'title' => 'Other Sermon',
            'service' => SermonService::Other,
            'date' => '2026-03-03',
        ]);

        $response = $this->get(route('sermons.service', 'other'));

        $response->assertOk();
        $response->assertSee('Other Services | Crockenhill Baptist Church');
        $response->assertSee('name="description"', false);
        $response->assertSee('content="Listen to recent Other sermons from Crockenhill Baptist Church."', false);
        // We now expect to see them globally
        $response->assertSee('type="application/rss+xml"', false);
    }
}
