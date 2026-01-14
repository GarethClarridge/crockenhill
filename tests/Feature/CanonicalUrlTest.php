<?php

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
        $response->assertSee('<link rel="canonical" href="' . url('/') . '">', false);
    }

    #[Test]
    public function sermon_page_has_canonical_url_pointing_to_slug_route(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'date' => '2024-01-15',
        ]);

        $slugRoute = route('showSermon', $sermon->slug);
        
        // Test slug-based route
        $response = $this->get($slugRoute);
        $response->assertStatus(200);
        $response->assertSee('<link rel="canonical" href="' . $slugRoute . '">', false);

        // Test date-based route
        $dateRoute = route('showSermonWithDate', [
            'year' => '2024',
            'month' => '01',
            'sermon' => $sermon->slug
        ]);
        
        $response = $this->get($dateRoute);
        $response->assertStatus(200);
        // It should still point to the slugRoute
        $response->assertSee('<link rel="canonical" href="' . $slugRoute . '">', false);
    }
}
