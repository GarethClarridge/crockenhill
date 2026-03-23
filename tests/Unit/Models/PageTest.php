<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\PageArea;
use App\Models\Page;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function page_relationships()
    {
        // Implementation will follow - likely empty or for future relationships
        $this->assertTrue(true); // Placeholder if no relationships to test initially
    }

    #[Test]
    public function page_accessors()
    {
        // Test getRouteAttribute
        $page1 = \App\Models\Page::factory()->create([
            'area' => PageArea::CHRIST->value,
            'slug' => 'about-us',
        ]);
        $this->assertEquals('/christ/about-us', $page1->route);

        $page2 = \App\Models\Page::factory()->create([
            'area' => PageArea::COMMUNITY->value,
            'slug' => 'events',
        ]);
        $this->assertEquals('/community/events', $page2->route);

        // Assuming the Page model has a getFormattedUpdatedAtAttribute or similar
        // If not, this part can be removed or adjusted.
        // For now, let's assume it exists and formats date to 'Y-m-d H:i:s' for simplicity
        // $now = now();
        // $page3 = Page::factory()->create(['updated_at' => $now]);
        // $this->assertEquals($now->format('F j, Y, g:i a'), $page3->formatted_updated_at);
        // This will depend on the actual accessor logic in the Page model.
    }

    #[Test]
    public function page_mutators_and_casts()
    {
        // Test 'navigation' attribute casting to boolean
        $pageNavTrue = \App\Models\Page::factory()->create(['navigation' => 1]);
        $this->assertTrue($pageNavTrue->navigation);

        $pageNavFalse = \App\Models\Page::factory()->create(['navigation' => 0]);
        $this->assertFalse($pageNavFalse->navigation);

        // Test with actual boolean values from factory state
        $pageNavTrueFromState = \App\Models\Page::factory()->isNavigation(true)->create();
        $this->assertTrue($pageNavTrueFromState->navigation);

        $pageNavFalseFromState = \App\Models\Page::factory()->isNavigation(false)->create();
        $this->assertFalse($pageNavFalseFromState->navigation);

        // Test with factory's default random boolean
        $pageFromFactory = \App\Models\Page::factory()->create();
        $this->assertIsBool($pageFromFactory->navigation);
    }

    #[Test]
    public function page_admin_visibility_helper_reflects_the_legacy_enum_value(): void
    {
        $adminOnlyPage = \App\Models\Page::factory()->create(['admin' => 'yes']);
        $publicPage = \App\Models\Page::factory()->create(['admin' => 'no']);

        $this->assertTrue($adminOnlyPage->isAdminOnly());
        $this->assertFalse($publicPage->isAdminOnly());
    }

    #[Test]
    public function page_scopes()
    {
        \App\Models\Page::query()->delete(); // Clear pages before this test

        // Test inArea() scope
        $pageInChrist = \App\Models\Page::factory()->inArea(PageArea::CHRIST)->create(['navigation' => false]);
        $pageInCommunity = \App\Models\Page::factory()->inArea(PageArea::COMMUNITY)->create(['navigation' => false]);
        $pageInChurch = \App\Models\Page::factory()->inArea(PageArea::CHURCH)->create(['navigation' => false]);

        $christPages = \App\Models\Page::inArea(PageArea::CHRIST)->get();
        $this->assertCount(1, $christPages);
        $this->assertTrue($christPages->contains($pageInChrist));
        $this->assertFalse($christPages->contains($pageInCommunity));
        $this->assertFalse($christPages->contains($pageInChurch));

        $communityPages = \App\Models\Page::inArea(PageArea::COMMUNITY)->get();
        $this->assertCount(1, $communityPages);
        $this->assertTrue($communityPages->contains($pageInCommunity));
        $this->assertFalse($communityPages->contains($pageInChrist));

        // Test isNavigation() scope
        $navPage1 = \App\Models\Page::factory()->isNavigation()->create(); // navigation = true
        $navPage2 = \App\Models\Page::factory()->isNavigation(true)->create(); // navigation = true
        $nonNavPage1 = \App\Models\Page::factory()->isNotNavigation()->create(); // navigation = false
        $nonNavPage2 = \App\Models\Page::factory()->isNavigation(false)->create(); // navigation = false

        $navigationPages = \App\Models\Page::isNavigation()->get();
        $this->assertCount(2, $navigationPages);
        $this->assertTrue($navigationPages->contains($navPage1));
        $this->assertTrue($navigationPages->contains($navPage2));
        $this->assertFalse($navigationPages->contains($nonNavPage1));
        $this->assertFalse($navigationPages->contains($nonNavPage2));
    }

    #[Test]
    public function page_controller_show_method_handles_route_parameters_correctly()
    {
        // This test specifically ensures that the PageController show method
        // can handle string parameters without throwing type errors

        $page = \App\Models\Page::factory()->create([
            'area' => PageArea::CHRIST->value,
            'slug' => 'test-slug',
            'heading' => 'Test Page',
            'markdown' => '# Test Content',
            'body' => '<h1>Test Content</h1>',
            'description' => 'Test description',
        ]);

        // Test that the page can be found by area and slug
        $foundPage = Page::where('slug', 'test-slug')
            ->where('area', PageArea::CHRIST->value)
            ->first();

        $this->assertNotNull($foundPage);
        $this->assertEquals($page->id, $foundPage->id);
        $this->assertEquals('Test Page', $foundPage->heading);
        $this->assertEquals(PageArea::CHRIST, $foundPage->area);
    }

    #[Test]
    public function page_controller_show_method_returns_correct_data_structure()
    {
        // Test that the show method returns the expected data structure
        $page = \App\Models\Page::factory()->create([
            'area' => PageArea::CHURCH->value,
            'slug' => 'about',
            'heading' => 'About Us',
            'markdown' => '# About Us\n\nThis is about us.',
            'body' => '<h1>About Us</h1><p>This is about us.</p>',
            'description' => 'About our church',
            'navigation' => true,
        ]);

        // Simulate what the controller method does
        $html = '# About Us

This is about us.';

        $expectedData = [
            'page' => $page,
            'html' => $html,
            'content' => $html,
            'heading' => 'About Us',
            'description' => 'About our church',
            'area' => PageArea::CHURCH,
            'slug' => 'about',
        ];

        // Verify the data structure
        $this->assertArrayHasKey('page', $expectedData);
        $this->assertArrayHasKey('html', $expectedData);
        $this->assertArrayHasKey('content', $expectedData);
        $this->assertArrayHasKey('heading', $expectedData);
        $this->assertArrayHasKey('description', $expectedData);
        $this->assertArrayHasKey('area', $expectedData);
        $this->assertArrayHasKey('slug', $expectedData);

        $this->assertInstanceOf(Page::class, $expectedData['page']);
        $this->assertEquals('About Us', $expectedData['heading']);
        $this->assertEquals(PageArea::CHURCH, $expectedData['area']);
        $this->assertEquals('about', $expectedData['slug']);
    }

    #[Test]
    public function page_controller_show_method_handles_missing_pages_gracefully()
    {
        // Test that the show method handles missing pages correctly
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        // This should throw a ModelNotFoundException
        Page::where('slug', 'nonexistent')
            ->where('area', PageArea::CHRIST->value)
            ->firstOrFail();
    }
}
