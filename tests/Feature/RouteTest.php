<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class RouteTest extends TestCase
{
    #[Test]
    public function main_routes_are_accessible_and_render_expected_content(): void
    {
        $routes = [
            '/' => 'Crockenhill Baptist Church',
            '/christ' => 'Christ',
            '/church' => 'Church',
            '/community' => 'Community',
        ];

        foreach ($routes as $uri => $expectedText) {
            $response = $this->get($uri);
            $response->assertStatus(200);
            $response->assertSee($expectedText);
        }
    }
} 