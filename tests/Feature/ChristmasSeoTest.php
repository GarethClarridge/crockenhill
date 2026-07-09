<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChristmasSeoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function christmas_page_has_correct_metadata_and_structured_data(): void
    {
        $response = $this->get(route('pages.christmas'));

        $response->assertStatus(200);

        // Basic SEO
        $response->assertSee('<title>Christmas | Crockenhill Baptist Church</title>', false);
        $response->assertSee('<meta name="description" content="Celebrate Christmas at Crockenhill Baptist Church. Join us for Carols by Candlelight, our Christmas Morning Service, and more festive events.">', false);

        // Open Graph
        $response->assertSee('<meta property="og:image" content="http://localhost/images/homepage/christmas2023.webp">', false);

        // WebPage Schema
        $response->assertSee('"@type": "WebPage"', false);
        $response->assertSee('"name": "Christmas"', false);
        $response->assertSee('"image": "http://localhost/images/homepage/christmas2023.webp"', false);

        // Breadcrumb Schema
        $response->assertSee('"@type": "BreadcrumbList"', false);
        $response->assertSee('"name": "Christmas"', false);

        // Event ItemList Schema
        $response->assertSee('"@type": "ItemList"', false);
        $response->assertSee('"@type": "Event"', false);

        // Check specific events
        $response->assertSee('"name": "Preparing Room"', false);
        $response->assertSee('"startDate": "2024-11-30T15:00:00"', false);
        $response->assertSee('"endDate": "2024-11-30T18:00:00"', false);

        $response->assertSee('"name": "Coffee Cup Carols"', false);
        $response->assertSee('"startDate": "2024-12-12T10:30:00"', false);

        $response->assertSee('"name": "Carols in the Chequers"', false);
        $response->assertSee('"startDate": "2024-12-18T19:30:00"', false);
        $response->assertSee('"name": "The Chequers, Crockenhill"', false);

        $response->assertSee('"name": "Carols by Candlelight"', false);
        $response->assertSee('"startDate": "2024-12-22T18:00:00"', false);

        $response->assertSee('"name": "Christmas Morning Service"', false);
        $response->assertSee('"startDate": "2024-12-25T10:30:00"', false);
    }
}
