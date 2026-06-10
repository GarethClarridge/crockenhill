<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RedirectsTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    public function test_contact_us_redirects_to_home(): void
    {
        $response = $this->get('/contactus');

        $response->assertStatus(301);
        $response->assertRedirect('/');
    }

    public function test_old_about_us_redirects_to_church(): void
    {
        $response = $this->get('/aboutus');

        $response->assertStatus(301);
        $response->assertRedirect('/church');
    }
}
