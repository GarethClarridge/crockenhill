<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FooterNavigationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function footer_evening_sermons_link_points_to_the_evening_service_page(): void
    {
        $response = $this->get(route('sermons.index'));

        $response->assertOk();

        // The "Listen to evening sermons" footer link must target the evening
        // service archive, not the unfiltered sermons index.
        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('sermons.service', 'evening'), '/').'"[^>]*>\s*Listen to evening sermons/',
            (string) $response->getContent(),
            'The footer "Listen to evening sermons" link should point to the evening service page.'
        );
    }
}
