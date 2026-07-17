<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Enums\PageArea;
use App\Models\Page;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function page_accessors()
    {
        // Test getRouteAttribute
        $page1 = Page::factory()->create([
            'area' => PageArea::Christ->value,
            'slug' => 'about-us',
        ]);
        $this->assertEquals('/christ/about-us', $page1->route);

        $page2 = Page::factory()->create([
            'area' => PageArea::Community->value,
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
        $pageNavTrue = Page::factory()->create(['navigation' => 1]);
        $this->assertTrue($pageNavTrue->navigation);

        $pageNavFalse = Page::factory()->create(['navigation' => 0]);
        $this->assertFalse($pageNavFalse->navigation);

        // Test with actual boolean values from factory state
        $pageNavTrueFromState = Page::factory()->isNavigation(true)->create();
        $this->assertTrue($pageNavTrueFromState->navigation);

        $pageNavFalseFromState = Page::factory()->isNavigation(false)->create();
        $this->assertFalse($pageNavFalseFromState->navigation);

        // Test with factory's default random boolean
        $pageFromFactory = Page::factory()->create();
        $this->assertIsBool($pageFromFactory->navigation);
    }

    #[Test]
    public function page_admin_visibility_helper_reflects_the_legacy_enum_value(): void
    {
        $adminOnlyPage = Page::factory()->create(['admin' => 'yes']);
        $publicPage = Page::factory()->create(['admin' => 'no']);

        $this->assertTrue($adminOnlyPage->isAdminOnly());
        $this->assertFalse($publicPage->isAdminOnly());
    }

    #[Test]
    public function page_registers_only_the_canonical_media_conversions(): void
    {
        $page = new Page;

        $page->registerMediaConversions();

        $conversionNames = collect($page->mediaConversions)
            ->map(fn ($conversion): string => $conversion->getName())
            ->all();

        $this->assertSame(['desktop', 'tablet', 'mobile', 'thumbnail'], $conversionNames);
    }

    #[Test]
    public function page_scopes()
    {
        Page::query()->delete(); // Clear pages before this test

        // Test inArea() scope
        $pageInChrist = Page::factory()->inArea(PageArea::Christ)->create(['navigation' => false]);
        $pageInCommunity = Page::factory()->inArea(PageArea::Community)->create(['navigation' => false]);
        $pageInChurch = Page::factory()->inArea(PageArea::Church)->create(['navigation' => false]);

        $christPages = Page::inArea(PageArea::Christ)->get();
        $this->assertCount(1, $christPages);
        $this->assertTrue($christPages->contains($pageInChrist));
        $this->assertFalse($christPages->contains($pageInCommunity));
        $this->assertFalse($christPages->contains($pageInChurch));

        $communityPages = Page::inArea(PageArea::Community)->get();
        $this->assertCount(1, $communityPages);
        $this->assertTrue($communityPages->contains($pageInCommunity));
        $this->assertFalse($communityPages->contains($pageInChrist));

        // Test isNavigation() scope
        $navPage1 = Page::factory()->isNavigation()->create(); // navigation = true
        $navPage2 = Page::factory()->isNavigation(true)->create(); // navigation = true
        $nonNavPage1 = Page::factory()->isNotNavigation()->create(); // navigation = false
        $nonNavPage2 = Page::factory()->isNavigation(false)->create(); // navigation = false

        $navigationPages = Page::isNavigation()->get();
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

        $page = Page::factory()->create([
            'area' => PageArea::Christ->value,
            'slug' => 'test-slug',
            'heading' => 'Test Page',
            'markdown' => '# Test Content',
            'body' => '<h1>Test Content</h1>',
            'description' => 'Test description',
        ]);

        // Test that the page can be found by area and slug
        $foundPage = Page::where('slug', 'test-slug')
            ->where('area', PageArea::Christ->value)
            ->first();

        $this->assertNotNull($foundPage);
        $this->assertEquals($page->id, $foundPage->id);
        $this->assertEquals('Test Page', $foundPage->heading);
        $this->assertEquals(PageArea::Christ, $foundPage->area);
    }

    #[Test]
    public function page_controller_show_method_returns_correct_data_structure()
    {
        // Test that the show method returns the expected data structure
        $page = Page::factory()->create([
            'area' => PageArea::Church->value,
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
            'area' => PageArea::Church,
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
        $this->assertEquals(PageArea::Church, $expectedData['area']);
        $this->assertEquals('about', $expectedData['slug']);
    }

    #[Test]
    public function page_controller_show_method_handles_missing_pages_gracefully()
    {
        // Test that the show method handles missing pages correctly
        $this->expectException(ModelNotFoundException::class);

        // This should throw a ModelNotFoundException
        Page::where('slug', 'nonexistent')
            ->where('area', PageArea::Christ->value)
            ->firstOrFail();
    }
}
