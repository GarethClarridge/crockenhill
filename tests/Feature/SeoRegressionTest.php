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

        $description = 'Listen to recent sermons from Crockenhill Baptist Church. Worshipping God, strengthening believers, and proclaiming Jesus Christ.';
        $response = $this->get(route('sermons.index'));

        $response->assertOk();
        $response->assertSee('<title>Sermons | Crockenhill Baptist Church</title>', false);
        $response->assertSee('<meta name="description" content="'.$description.'">', false);
        $response->assertSee('<meta property="og:description" content="'.$description.'">', false);
        $response->assertSee(
            '<link rel="alternate" type="application/rss+xml" title="Sunday Morning Sermons" href="'.route('podcast.feed', 'morning').'">',
            false
        );
        $response->assertSee(
            '<link rel="alternate" type="application/rss+xml" title="Sunday Evening Sermons" href="'.route('podcast.feed', 'evening').'">',
            false
        );
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
            $response->assertSee("<title>{$label} Services | Crockenhill Baptist Church</title>", false);
            $response->assertSee('<meta name="description" content="'.$description.'">', false);
            $response->assertSee(
                '<link rel="alternate" type="application/rss+xml" title="Sunday Morning Sermons" href="'.route('podcast.feed', 'morning').'">',
                false
            );
            $response->assertSee(
                '<link rel="alternate" type="application/rss+xml" title="Sunday Evening Sermons" href="'.route('podcast.feed', 'evening').'">',
                false
            );
        }
    }

    #[Test]
    public function other_service_page_includes_podcast_discovery_links(): void
    {
        Sermon::factory()->create([
            'title' => 'Other Sermon',
            'service' => SermonService::Other,
            'date' => '2026-03-03',
        ]);

        $response = $this->get(route('sermons.service', 'other'));

        $response->assertOk();
        $response->assertSee('<title>Other Services | Crockenhill Baptist Church</title>', false);
        $response->assertSee(
            '<meta name="description" content="Listen to recent Other sermons from Crockenhill Baptist Church.">',
            false
        );
        $response->assertSee(
            '<link rel="alternate" type="application/rss+xml" title="Sunday Morning Sermons" href="'.route('podcast.feed', 'morning').'">',
            false
        );
        $response->assertSee(
            '<link rel="alternate" type="application/rss+xml" title="Sunday Evening Sermons" href="'.route('podcast.feed', 'evening').'">',
            false
        );
    }
}
