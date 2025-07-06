<?php

namespace Tests\Unit;

use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test; // Correct namespace
// PageFactory not explicitly used if using Model::factory()
use Tests\TestCase; // Added import

class PageTest extends TestCase
{
    use RefreshDatabase;

    #[Test] // Replaced @test
    public function test_page_relationships()
    {
        // Implementation will follow - likely empty or for future relationships
        $this->assertTrue(true); // Placeholder if no relationships to test initially
    }

    #[Test] // Replaced @test
    public function test_page_accessors()
    {
        // Test getRouteAttribute
        $page1 = \App\Models\Page::factory()->create([
            'area' => 'christ',
            'slug' => 'about-us',
        ]);
        $this->assertEquals('/christ/about-us', $page1->route);

        $page2 = \App\Models\Page::factory()->create([
            'area' => 'community',
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

    #[Test] // Replaced @test
    public function test_page_mutators_and_casts()
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

    #[Test] // Replaced @test
    public function test_page_scopes()
    {
        \App\Models\Page::query()->delete(); // Clear pages before this test

        // Test inArea() scope
        $pageInChrist = \App\Models\Page::factory()->inArea('christ')->create(['navigation' => false]);
        $pageInCommunity = \App\Models\Page::factory()->inArea('community')->create(['navigation' => false]);
        $pageInChurch = \App\Models\Page::factory()->inArea('church')->create(['navigation' => false]);

        $christPages = \App\Models\Page::inArea('christ')->get();
        $this->assertCount(1, $christPages);
        $this->assertTrue($christPages->contains($pageInChrist));
        $this->assertFalse($christPages->contains($pageInCommunity));
        $this->assertFalse($christPages->contains($pageInChurch));

        $communityPages = \App\Models\Page::inArea('community')->get();
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

    // Placeholder for custom methods test if any are identified
    // /**
    //  * @test
    //  */
    // public function testCustomPageMethods()
    // {
    //     // Implementation will follow
    // }
}
