<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RedirectsTest extends TestCase
{
    #[Test]
    public function it_redirects_legacy_contactus_url(): void
    {
        $response = $this->get('/contactus');
        $response->assertRedirect('/');
        $response->assertStatus(301);
    }

    #[Test]
    public function it_redirects_legacy_aboutus_url(): void
    {
        $response = $this->get('/aboutus');
        $response->assertRedirect('/church');
        $response->assertStatus(301);
    }
}
