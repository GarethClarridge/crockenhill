<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PageArea;
use App\Models\Page;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchPageTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_renders_required_cards_on_the_church_page_even_if_moved_between_areas(): void
    {
        // Place one required page in community and another in church
        Page::factory()->create([
            'slug' => 'sunday-mornings',
            'area' => PageArea::Community,
            'heading' => 'Sunday mornings',
            'admin' => 'no',
        ]);
        Page::factory()->create([
            'slug' => 'sunday-evenings',
            'area' => PageArea::Church, // Moved to church area
            'heading' => 'Sunday evenings',
            'admin' => 'no',
        ]);
        Page::factory()->create([
            'slug' => 'bible-study',
            'area' => PageArea::Community,
            'heading' => 'Bible study',
            'admin' => 'no',
        ]);

        $response = $this->get('/church');

        $response->assertStatus(200);
        $response->assertSee('Sunday mornings');
        $response->assertSee('Sunday evenings');
        $response->assertSee('Bible study');
    }

    #[Test]
    public function it_excludes_privacy_notice_from_related_pages_on_church_landing_page(): void
    {
        // The church landing page (/church) has a hardcoded card for privacy-notice.
        // It should be excluded from the "links" collection provided to the related-pages component.

        Page::factory()->create([
            'slug' => 'privacy-notice',
            'area' => PageArea::Church,
            'heading' => 'Privacy notice',
            'admin' => 'no',
        ]);

        Page::factory()->create([
            'slug' => 'other-page',
            'area' => PageArea::Church,
            'heading' => 'Other church page',
            'admin' => 'no',
        ]);

        $response = $this->get('/church');

        $response->assertStatus(200);

        // It will be seen in the hardcoded card section
        $response->assertSee('Privacy notice');

        // We verify that the "Related pages" section does NOT contain it.
        // The related-pages component is wrapped in a section with data-testid="related-pages"
        $content = $response->getContent();
        $relatedPagesSection = strstr($content, 'data-testid="related-pages"');

        $this->assertStringNotContainsString('Learn about Privacy notice', $relatedPagesSection ?: '');
        $this->assertStringContainsString('Learn about Other church page', $relatedPagesSection ?: '');
    }
}
