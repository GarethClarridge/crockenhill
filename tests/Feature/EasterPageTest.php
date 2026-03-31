<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EasterPageTest extends TestCase
{
    #[Test]
    public function easter_page_renders_service_details_and_external_link(): void
    {
        $response = $this->get(route('easter'));

        $response->assertOk();
        $response->assertSee('Easter services');
        $response->assertSee('Friday 3 April 2026');
        $response->assertSee('Good Friday');
        $response->assertSee('Sunday 5 April 2026');
        $response->assertSee('Easter Sunday');
        $response->assertSee('Elmstead Baptist Church');
        $response->assertSee('to remember the death of Jesus Christ in our place.');
        $response->assertSee('10:30AM');
        $response->assertSee('6:00PM');
        $response->assertSee('Join us at Crockenhill Baptist Church as we celebrate the resurrection of Jesus Christ.');
        $response->assertSee('https://elmsteadbaptistchurch.org.uk/', false);
    }

    #[Test]
    public function easter_page_has_expected_metadata(): void
    {
        $response = $this->get(route('easter'));

        $response->assertOk();
        $response->assertSee('<title>Easter services | Crockenhill Baptist Church</title>', false);
        $response->assertSee(
            '<meta name="description" content="Join us for our Easter services at Crockenhill Baptist Church on Good Friday and Easter Sunday in April 2026.">',
            false
        );
        $response->assertSee('<link rel="canonical" href="'.url('/easter').'">', false);
    }
}
