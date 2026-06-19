<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonContentType;
use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Wiring smoke tests for the client-side Google Analytics event tracking (GA3)
 * and content dimensions (GA4). The JavaScript itself isn't exercised here, but
 * the data-analytics / data-ga-* attributes it reads are PHP-rendered, so we can
 * prove the markup is wired correctly.
 */
class SermonAnalyticsTrackingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sermon_page_annotates_audio_player_and_download_for_analytics(): void
    {
        $sermon = Sermon::factory()->withAudio()->create();

        $response = $this->followingRedirects()->get(route('sermons.show', $sermon->slug));

        $response->assertStatus(200);
        $response->assertSee('data-analytics="sermon-media"', false);
        $response->assertSee('data-analytics="sermon-download"', false);
        $response->assertSee('data-ga-sermon-slug="'.$sermon->slug.'"', false);
    }

    #[Test]
    public function sermon_page_emits_content_dimensions_for_a_sermon(): void
    {
        $sermon = Sermon::factory()->byPreacher('Mark Evans')->create([
            'series' => 'Letters to Rome',
            'content_type' => SermonContentType::Sermon,
        ]);

        $response = $this->followingRedirects()->get(route('sermons.show', $sermon->slug));

        $response->assertStatus(200);
        $response->assertSee('data-ga-context', false);
        $response->assertSee('data-ga-content-type="sermon"', false);
        $response->assertSee('data-ga-content-group="Sermons"', false);
        $response->assertSee('data-ga-preacher="Mark Evans"', false);
        $response->assertSee('data-ga-series="Letters to Rome"', false);
    }

    #[Test]
    public function sermon_page_opts_in_the_reference_copy_button_as_a_share(): void
    {
        $sermon = Sermon::factory()->create([
            'reference' => 'Romans 8:28',
            'scripture_passage_id' => null,
        ]);

        $response = $this->followingRedirects()->get(route('sermons.show', $sermon->slug));

        $response->assertStatus(200);
        $response->assertSee("window.gaTrackEvent('share'", false);
    }

    #[Test]
    public function childrens_corner_page_emits_childrens_content_group(): void
    {
        $this->actingAs(User::factory()->create());

        $talk = Sermon::factory()->withAudio()->create([
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        $response = $this->get(route('childrens-corner.show', $talk->slug));

        $response->assertStatus(200);
        $response->assertSee('data-ga-content-type="childrens_talk"', false);
        $response->assertSee('data-ga-content-group="Children&#039;s Corner"', false);
    }
}
