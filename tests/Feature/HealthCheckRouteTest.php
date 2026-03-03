<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckRouteTest extends TestCase
{
    public function test_health_route_returns_success(): void
    {
        $this->get('/up')->assertSuccessful();
    }
}
