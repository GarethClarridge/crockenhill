<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CanonicalUrlTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function homepage_has_correct_canonical_url(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('<link rel="canonical" href="'.url('/').'">', false);
    }

    #[Test]
    public function sermon_page_has_canonical_url_pointing_to_date_route(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'date' => '2024-01-15',
        ]);

        $canonicalUrl = url('/christ/sermons/2024/01/test-sermon');

        $dateRoute = route('sermons.show.dated', [
            'year' => '2024',
            'month' => '01',
            'sermon' => $sermon->slug,
        ]);

        // The date-based route renders with the date-based canonical.
        $response = $this->get($dateRoute);
        $response->assertStatus(200);
        $response->assertSee('<link rel="canonical" href="'.$canonicalUrl.'">', false);

        // The slug-only route 301-redirects to the canonical date-based URL.
        $slugRoute = route('sermons.show', $sermon->slug);
        $this->get($slugRoute)->assertRedirect($canonicalUrl)->assertStatus(301);
    }
}
