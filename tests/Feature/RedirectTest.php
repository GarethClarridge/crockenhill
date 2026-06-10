<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class RedirectTest extends TestCase
{
    public function test_contactus_redirect(): void
    {
        $response = $this->get('/contactus');
        $response->assertStatus(301);
        $response->assertRedirect('/');
    }

    public function test_aboutus_redirect(): void
    {
        $response = $this->get('/aboutus');
        $response->assertStatus(301);
        $response->assertRedirect('/church');
    }
}
