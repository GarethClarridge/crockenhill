<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomepageTest extends TestCase
{

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_homepage_returns_a_successful_response()
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
