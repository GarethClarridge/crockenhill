<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PageArea;
use App\Models\Page;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Wiring smoke tests for the BreadcrumbList JSON-LD across page types.
 *
 * The sermon Article/Audio/Video schema shape is covered by SermonJsonLdTest;
 * the values those blocks carry are covered at the presenter level in
 * Tests\Integration\Presenters\SermonViewPresenterTest. What remains here is the
 * breadcrumb trail, built by the breadcrumb component from the request path —
 * these two tests prove it renders correctly on a deep sermon page and on a
 * generic content page.
 */
class StructuredDataTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sermon_page_contains_breadcrumb_list_schema(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Breadcrumb Test Sermon',
            'date' => '2023-12-25',
        ]);

        $year = $sermon->date->format('Y');
        $month = $sermon->date->format('m');

        $response = $this->get("/christ/sermons/{$year}/{$month}/{$sermon->slug}");

        $response->assertStatus(200);
        $response->assertSee('"@type": "BreadcrumbList"', false);
        $response->assertSee('"name": "Home"', false);
        $response->assertSee('"name": "Christ"', false);
        $response->assertSee('"name": "Sermons"', false);
        $response->assertSee('"name": "Breadcrumb Test Sermon"', false);
    }

    #[Test]
    public function generic_page_contains_breadcrumb_list_schema(): void
    {
        Page::factory()->create([
            'heading' => 'About Us',
            'area' => PageArea::Church,
            'slug' => 'about-us',
        ]);

        $response = $this->get('/church/about-us');

        $response->assertStatus(200);
        $response->assertSee('"@type": "BreadcrumbList"', false);
        $response->assertSee('"name": "Home"', false);
        $response->assertSee('"name": "Church"', false);
        $response->assertSee('"name": "About Us"', false);
    }
}
