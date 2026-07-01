<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonBreadcrumbTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_includes_series_in_breadcrumb_trail_for_dated_sermon_page(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon',
            'slug' => 'test-sermon',
            'series' => 'Gospel of John',
            'date' => '2024-05-12',
        ]);

        $routeUrl = route('sermons.show.dated', [
            'year' => '2024',
            'month' => '05',
            'sermon' => $sermon->slug,
        ]);

        $response = $this->get($routeUrl);
        $response->assertStatus(200);

        // Check if the names appear in the breadcrumb list
        $response->assertSee('Series');
        $response->assertSee('Gospel of John');

        // Confirm the links are there
        $response->assertSee(route('sermons.series'));
        $response->assertSee(route('sermons.series.show', ['series' => 'gospel-of-john']));

        // Confirm JSON-LD breadcrumb exists
        $response->assertSee('BreadcrumbList');
    }

    #[Test]
    public function it_omits_series_from_breadcrumb_trail_when_sermon_has_no_series(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Standalone Sermon',
            'slug' => 'standalone-sermon',
            'series' => null,
            'date' => '2024-05-12',
        ]);

        $routeUrl = route('sermons.show.dated', [
            'year' => '2024',
            'month' => '05',
            'sermon' => $sermon->slug,
        ]);

        $response = $this->get($routeUrl);
        $response->assertStatus(200);

        $response->assertSee('Standalone Sermon');

        // The most reliable check is that it's NOT in the breadcrumb nav.
        $response->assertDontSee('<nav aria-label="Breadcrumb">.*Series', false);
    }
}
