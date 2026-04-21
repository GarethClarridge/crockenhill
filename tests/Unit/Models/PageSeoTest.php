<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageSeoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_description_as_meta_description_when_present(): void
    {
        $page = Page::factory()->create([
            'heading' => 'Test Page',
            'description' => 'This is a great page description that provides useful information about the page content.',
        ]);

        $metaDescription = $page->meta_description;

        $this->assertEquals(
            'This is a great page description that provides useful information about the page content.',
            $metaDescription
        );
    }

    #[Test]
    public function it_truncates_long_description_to_155_characters(): void
    {
        $longDescription = str_repeat('This is a very long description that will definitely exceed the 155 character limit. ', 5);

        $page = Page::factory()->create([
            'heading' => 'Test Page',
            'description' => $longDescription,
        ]);

        $metaDescription = $page->meta_description;

        $this->assertLessThanOrEqual(158, strlen($metaDescription));
        $this->assertStringEndsWith('...', $metaDescription);
    }

    #[Test]
    public function it_strips_html_tags_from_description(): void
    {
        $page = Page::factory()->create([
            'heading' => 'Test Page',
            'description' => '<p>This description has <strong>HTML tags</strong> and <em>formatting</em> that should be removed.</p>',
        ]);

        $metaDescription = $page->meta_description;

        $this->assertStringNotContainsString('<p>', $metaDescription);
        $this->assertStringNotContainsString('<strong>', $metaDescription);
        $this->assertStringNotContainsString('<em>', $metaDescription);
        $this->assertStringContainsString('This description has HTML tags', $metaDescription);
    }

    #[Test]
    public function it_falls_back_to_heading_when_description_is_empty(): void
    {
        $page = Page::factory()->create([
            'heading' => 'Sunday Morning Services',
            'description' => '',
        ]);

        $metaDescription = $page->meta_description;

        $this->assertEquals('Sunday Morning Services', $metaDescription);
    }

    #[Test]
    public function it_falls_back_to_heading_when_description_is_null(): void
    {
        $page = Page::factory()->create([
            'heading' => 'Bible Study Groups',
            'description' => '',
        ]);

        $metaDescription = $page->meta_description;

        $this->assertEquals('Bible Study Groups', $metaDescription);
    }

    #[Test]
    public function it_truncates_heading_fallback_to_155_characters(): void
    {
        // Create a heading that's 200 characters (longer than 155 but fits in DB column)
        $longHeading = str_repeat('Long ', 40); // 200 characters

        $page = Page::factory()->create([
            'heading' => $longHeading,
            'description' => '',
        ]);

        $metaDescription = $page->meta_description;

        $this->assertLessThanOrEqual(158, strlen($metaDescription));
    }

    #[Test]
    public function it_handles_description_with_only_whitespace(): void
    {
        $page = Page::factory()->create([
            'heading' => 'Test Heading',
            'description' => '   ',
        ]);

        $metaDescription = $page->meta_description;

        // Should treat whitespace-only description as empty and fall back to heading
        $this->assertEquals('Test Heading', $metaDescription);
    }

    #[Test]
    public function it_preserves_descriptions_under_155_characters(): void
    {
        $shortDescription = 'A short but informative description of this page.';

        $page = Page::factory()->create([
            'heading' => 'Test Page',
            'description' => $shortDescription,
        ]);

        $metaDescription = $page->meta_description;

        $this->assertEquals($shortDescription, $metaDescription);
        $this->assertStringNotContainsString('...', $metaDescription);
    }

    #[Test]
    public function it_handles_description_with_exactly_155_characters(): void
    {
        // Create a description that's exactly 155 characters
        $exactDescription = str_pad('This is exactly 155 characters', 155, '.');

        $page = Page::factory()->create([
            'heading' => 'Test Page',
            'description' => $exactDescription,
        ]);

        $metaDescription = $page->meta_description;

        $this->assertEquals(155, strlen($metaDescription));
        $this->assertEquals($exactDescription, $metaDescription);
    }

    #[Test]
    public function it_strips_tags_before_truncating(): void
    {
        $htmlDescription = '<p>'.str_repeat('Word ', 50).'</p>';

        $page = Page::factory()->create([
            'heading' => 'Test Page',
            'description' => $htmlDescription,
        ]);

        $metaDescription = $page->meta_description;

        // Should not contain HTML
        $this->assertStringNotContainsString('<p>', $metaDescription);
        $this->assertStringNotContainsString('</p>', $metaDescription);
        // Should be truncated
        $this->assertLessThanOrEqual(158, strlen($metaDescription));
    }

    #[Test]
    public function it_returns_route_with_area_and_slug(): void
    {
        $slug = 'sunday-mornings-seo-test';

        $page = Page::factory()->create([
            'slug' => $slug,
            'area' => 'church',
        ]);

        $route = $page->route;

        $this->assertEquals('/church/'.$slug, $route);
    }

    #[Test]
    public function it_returns_null_route_when_slug_is_empty(): void
    {
        $page = Page::factory()->make([
            'slug' => '',
            'area' => 'church',
        ]);

        $route = $page->route;

        $this->assertNull($route);
    }

    #[Test]
    public function it_trims_slashes_from_area_and_slug_in_route(): void
    {
        $page = Page::factory()->make([
            'slug' => '/sunday-mornings/',
            'area' => 'church',
        ]);

        $route = $page->route;

        $this->assertEquals('/church/sunday-mornings', $route);
        $this->assertStringNotContainsString('//', $route);
    }

    #[Test]
    public function multiple_pages_can_have_different_meta_descriptions(): void
    {
        $page1 = Page::factory()->create([
            'heading' => 'Page One',
            'description' => 'Description for page one',
        ]);

        $page2 = Page::factory()->create([
            'heading' => 'Page Two',
            'description' => 'Description for page two',
        ]);

        $this->assertEquals('Description for page one', $page1->meta_description);
        $this->assertEquals('Description for page two', $page2->meta_description);
        $this->assertNotEquals($page1->meta_description, $page2->meta_description);
    }
}
