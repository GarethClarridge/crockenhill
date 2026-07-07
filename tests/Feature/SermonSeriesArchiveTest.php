<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SermonSeriesArchiveTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_resolve_a_series_archive_with_an_apostrophe_in_the_name(): void
    {
        $seriesName = "Children's Talks";
        $sermon = Sermon::factory()->create([
            'series' => $seriesName,
            'title' => 'The Prodigal Son',
        ]);

        // The slug should be 'childrens-talks'
        $response = $this->get('/christ/sermons/series/childrens-talks');

        $response->assertStatus(200);
        $response->assertSee($seriesName);
        $response->assertSee('The Prodigal Son');
    }

    #[Test]
    public function it_can_resolve_a_series_archive_with_mixed_casing_and_prepositions(): void
    {
        $seriesName = "Gospel of John";
        $sermon = Sermon::factory()->create([
            'series' => $seriesName,
            'title' => 'In the Beginning',
        ]);

        // The slug should be 'gospel-of-john'
        $response = $this->get('/christ/sermons/series/gospel-of-john');

        $response->assertStatus(200);
        $response->assertSee($seriesName);
        $response->assertSee('In the Beginning');
    }

    #[Test]
    public function it_returns_404_for_non_existent_series(): void
    {
        $response = $this->get('/christ/sermons/series/non-existent-series');

        $response->assertStatus(404);
    }
}
