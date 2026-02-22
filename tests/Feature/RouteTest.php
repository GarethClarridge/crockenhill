<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PageArea;
use App\Models\Page;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RouteTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function main_routes_are_accessible_and_render_expected_content(): void
    {
        $routes = [
            '/' => 'Crockenhill Baptist Church',
            '/christ' => 'Christ',
            '/church' => 'Church',
            '/community' => 'Community',
        ];

        foreach ($routes as $uri => $expectedText) {
            $response = $this->get($uri);
            $response->assertStatus(200);
            $response->assertSee($expectedText);
        }
    }

    #[Test]
    public function public_page_routes_work_correctly_with_valid_pages(): void
    {
        // Create test pages
        $christPage = Page::factory()->create([
            'area' => PageArea::CHRIST->value,
            'slug' => 'test-page',
            'heading' => 'Test Page',
            'markdown' => '# Test Content',
            'body' => '<h1>Test Content</h1>',
            'description' => 'A test page',
            'navigation' => true,
            'admin' => 'no',
        ]);

        $churchPage = Page::factory()->create([
            'area' => PageArea::CHURCH->value,
            'slug' => 'about-us',
            'heading' => 'About Us',
            'markdown' => '# About Us',
            'body' => '<h1>About Us</h1>',
            'description' => 'About our church',
            'navigation' => true,
            'admin' => 'no',
        ]);

        // Test that pages are accessible via their routes
        $response = $this->get('/christ/test-page');
        $response->assertStatus(200);
        $response->assertSee('Test Page');
        $response->assertSee('Test Content');

        $response = $this->get('/church/about-us');
        $response->assertStatus(200);
        $response->assertSee('About Us');
    }

    #[Test]
    public function public_page_routes_return_404_for_nonexistent_pages(): void
    {
        // Test with non-existent page
        $response = $this->get('/christ/nonexistent-page');
        $response->assertStatus(404);

        $response = $this->get('/church/missing-page');
        $response->assertStatus(404);
    }

    #[Test]
    public function public_page_routes_return_404_for_wrong_area(): void
    {
        // Create a page in 'christ' area
        Page::factory()->create([
            'area' => PageArea::CHRIST->value,
            'slug' => 'test-page',
            'heading' => 'Test Page',
            'markdown' => '# Test Content',
            'body' => '<h1>Test Content</h1>',
            'description' => 'Test description',
            'admin' => 'no',
        ]);

        // Try to access it via wrong area
        $response = $this->get('/church/test-page');
        $response->assertStatus(404);

        $response = $this->get('/community/test-page');
        $response->assertStatus(404);
    }

    #[Test]
    public function public_page_routes_handle_special_characters_in_slugs(): void
    {
        // Create a page with special characters in slug
        Page::factory()->create([
            'area' => PageArea::CHRIST->value,
            'slug' => 'test-page-with-dashes',
            'heading' => 'Test Page With Dashes',
            'markdown' => '# Test Content',
            'body' => '<h1>Test Content</h1>',
            'description' => 'Test description',
            'admin' => 'no',
        ]);

        $response = $this->get('/christ/test-page-with-dashes');
        $response->assertStatus(200);
        $response->assertSee('Test Page With Dashes');
    }

    #[Test]
    public function public_page_routes_work_with_empty_markdown(): void
    {
        // Create a page with empty markdown
        Page::factory()->create([
            'area' => PageArea::CHRIST->value,
            'slug' => 'empty-page',
            'heading' => 'Empty Page',
            'markdown' => '',
            'body' => '',
            'description' => 'Empty page description',
            'admin' => 'no',
        ]);

        $response = $this->get('/christ/empty-page');
        $response->assertStatus(200);
        $response->assertSee('Empty Page');
    }

    #[Test]
    public function public_page_markdown_strips_script_tags(): void
    {
        $slug = 'security-script-tag-page-'.Str::lower(Str::random(8));

        Page::factory()->create([
            'area' => PageArea::CHRIST->value,
            'slug' => $slug,
            'heading' => 'Security Script Tag Page',
            'markdown' => '<script>alert("page-xss")</script>'."\n\n".'Safe page content.',
            'body' => '',
            'description' => 'Security test page',
            'admin' => 'no',
        ]);

        $response = $this->get("/christ/{$slug}");

        $response->assertStatus(200);
        $response->assertSee('Safe page content.');
        $response->assertDontSee('<script>alert("page-xss")</script>', false);
    }

    #[Test]
    public function public_page_markdown_blocks_javascript_links(): void
    {
        $slug = 'security-javascript-link-page-'.Str::lower(Str::random(8));

        Page::factory()->create([
            'area' => PageArea::CHRIST->value,
            'slug' => $slug,
            'heading' => 'Security Javascript Link Page',
            'markdown' => '[Click me](javascript:alert("page-link"))',
            'body' => '',
            'description' => 'Security link test page',
            'admin' => 'no',
        ]);

        $response = $this->get("/christ/{$slug}");

        $response->assertStatus(200);
        $response->assertSee('Click me');
        $response->assertDontSee('javascript:alert("page-link")', false);
    }

    #[Test]
    public function public_page_routes_handle_empty_description(): void
    {
        // Create a page with empty description (not null since it's required)
        $page = Page::factory()->create([
            'area' => PageArea::CHRIST->value,
            'slug' => 'no-description',
            'heading' => 'No Description',
            'markdown' => '# Content',
            'body' => '<h1>Content</h1>',
            'description' => '', // Empty string instead of null
            'admin' => 'no',
        ]);

        $response = $this->get('/christ/no-description');
        $response->assertStatus(200);
        $response->assertSee('No Description');
    }

    #[Test]
    public function area_only_routes_work_correctly(): void
    {
        // Test the area-only route (showPage method)
        $response = $this->get('/christ/');
        $response->assertStatus(200);
    }

    #[Test]
    public function route_model_binding_errors_are_prevented(): void
    {
        // This test ensures that the route model binding error we fixed doesn't occur again
        // The error was: "Argument #1 must be of type App\Models\Page, string given"

        // Create a page
        $page = Page::factory()->create([
            'area' => PageArea::CHRIST->value,
            'slug' => 'binding-test',
            'heading' => 'Binding Test',
            'markdown' => '# Test',
            'body' => '<h1>Test</h1>',
            'description' => 'Binding test description',
            'admin' => 'no',
        ]);

        // This should not throw a TypeError about argument types
        $response = $this->get('/christ/binding-test');

        // If we get here without a TypeError, the fix is working
        $response->assertStatus(200);
        $response->assertSee('Binding Test');
    }

    #[Test]
    public function multiple_pages_in_same_area_work_independently(): void
    {
        // Create multiple pages in the same area
        $page1 = Page::factory()->create([
            'area' => PageArea::CHRIST->value,
            'slug' => 'first-page',
            'heading' => 'First Page',
            'markdown' => '# First',
            'body' => '<h1>First</h1>',
            'description' => 'First page description',
            'admin' => 'no',
        ]);

        $page2 = Page::factory()->create([
            'area' => PageArea::CHRIST->value,
            'slug' => 'second-page',
            'heading' => 'Second Page',
            'markdown' => '# Second',
            'body' => '<h1>Second</h1>',
            'description' => 'Second page description',
            'admin' => 'no',
        ]);

        // Test that each page shows its own content correctly
        $response1 = $this->get('/christ/first-page');
        $response1->assertStatus(200);
        $response1->assertSee('First Page'); // Check the page heading
        $response1->assertSee('First'); // Check the page content

        $response2 = $this->get('/christ/second-page');
        $response2->assertStatus(200);
        $response2->assertSee('Second Page'); // Check the page heading
        $response2->assertSee('Second'); // Check the page content

        // Note: Related pages sections may show other pages in the same area as links,
        // which is correct behavior and helps with navigation.
    }

    #[Test]
    public function pages_with_unique_slugs_in_different_areas_work_correctly(): void
    {
        // Create pages with unique slugs in different areas
        $christPage = Page::factory()->create([
            'area' => PageArea::CHRIST->value,
            'slug' => 'christ-page',
            'heading' => 'Christ Page',
            'markdown' => '# Christ',
            'body' => '<h1>Christ</h1>',
            'description' => 'Christ page description',
            'admin' => 'no',
        ]);

        $churchPage = Page::factory()->create([
            'area' => PageArea::CHURCH->value,
            'slug' => 'church-page',
            'heading' => 'Church Page',
            'markdown' => '# Church',
            'body' => '<h1>Church</h1>',
            'description' => 'Church page description',
            'admin' => 'no',
        ]);

        // Test both pages work correctly with unique slugs
        $response1 = $this->get('/christ/christ-page');
        $response1->assertStatus(200);
        $response1->assertSee('Christ Page');
        $response1->assertSee('<h1>Christ</h1>', false); // Check the Christ page content
        $response1->assertDontSee('<h1>Church</h1>', false); // Ensure Church page content is not present

        $response2 = $this->get('/church/church-page');
        $response2->assertStatus(200);
        $response2->assertSee('Church Page');
        $response2->assertSee('<h1>Church</h1>', false); // Check the Church page content
        $response2->assertDontSee('<h1>Christ</h1>', false); // Ensure Christ page content is not present
    }

    #[Test]
    public function debug_page_creation_and_routing(): void
    {
        // Create a test page to verify routing works correctly
        $page = Page::factory()->create([
            'area' => PageArea::CHRIST->value,
            'slug' => 'debug-test',
            'heading' => 'Debug Test',
            'markdown' => '# Debug',
            'body' => '<h1>Debug</h1>',
            'description' => 'Debug test description',
            'admin' => 'no',
        ]);

        // Test the route
        $response = $this->get('/christ/debug-test');
        $response->assertStatus(200);
        $response->assertSee('Debug Test');
    }

    #[Test]
    public function navigation_pages_appear_in_navigation_header(): void
    {
        // Create navigation pages for each area
        $christPage = Page::factory()->create([
            'area' => PageArea::CHRIST->value,
            'slug' => 'nav-christ',
            'heading' => 'Nav Christ',
            'navigation' => true,
            'admin' => 'no',
        ]);
        $churchPage = Page::factory()->create([
            'area' => PageArea::CHURCH->value,
            'slug' => 'nav-church',
            'heading' => 'Nav Church',
            'navigation' => true,
            'admin' => 'no',
        ]);
        $communityPage = Page::factory()->create([
            'area' => PageArea::COMMUNITY->value,
            'slug' => 'nav-community',
            'heading' => 'Nav Community',
            'navigation' => true,
            'admin' => 'no',
        ]);

        // Visit a page that renders the header
        $response = $this->get('/christ/nav-christ');
        $response->assertStatus(200);
        // Check that navigation links are present
        $response->assertSee('Nav Christ');
        $response->assertSee('Nav Church');
        $response->assertSee('Nav Community');
        // Check that the links are correct
        $response->assertSee('/christ/nav-christ');
        $response->assertSee('/church/nav-church');
        $response->assertSee('/community/nav-community');
    }

    #[Test]
    public function community_slug_renders_meeting_view_when_meeting_exists(): void
    {
        // Create a meeting with a known slug
        $meeting = \App\Models\Meeting::factory()->create([
            'slug' => 'test-meeting',
            'day' => 'Monday',
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'location' => 'Test Hall',
            'who' => 'Everyone',
            'pictures' => false,
            'leaders_phone' => '0123456789',
            'leaders_email' => 'leader@example.com',
            'type' => 'Adults',
        ]);

        $response = $this->get('/community/test-meeting');
        $response->assertStatus(200);
        // Assert that meeting details are present
        $response->assertSee('Monday');
        $response->assertSee('10:00am');
        $response->assertSee('11:00am');
        $response->assertSee('Test Hall');
        $response->assertSee('Everyone');
        $response->assertSee('0123456789');
        $response->assertSee('leader@example.com');
        // Should not see generic page fallback content (e.g. markdown body)
        $response->assertDontSee('Test Content');
    }
}
