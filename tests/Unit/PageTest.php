<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Crockenhill\Page; // Correct namespace
use Database\Factories\PageFactory;

class PageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function testPageRelationships()
    {
        // Implementation will follow - likely empty or for future relationships
        $this->assertTrue(true); // Placeholder if no relationships to test initially
    }

    /**
     * @test
     */
    public function testPageAccessors()
    {
        // Test getRouteAttribute
        $page1 = Page::factory()->create([
            'area' => 'christ',
            'slug' => 'about-us',
        ]);
        $this->assertEquals('/christ/about-us', $page1->route);

        $page2 = Page::factory()->create([
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

    /**
     * @test
     */
    public function testPageMutatorsAndCasts()
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

    /**
     * @test
     */
    public function testPageScopes()
    {
        // Test inArea() scope
        $pageInChrist = Page::factory()->inArea('christ')->create();
        $pageInCommunity = Page::factory()->inArea('community')->create();
        $pageInChurch = Page::factory()->inArea('church')->create();

        $christPages = Page::inArea('christ')->get();
        $this->assertCount(1, $christPages);
        $this->assertTrue($christPages->contains($pageInChrist));
        $this->assertFalse($christPages->contains($pageInCommunity));
        $this->assertFalse($christPages->contains($pageInChurch));

        $communityPages = Page::inArea('community')->get();
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

    // Placeholder for custom methods test if any are identified
    // /**
    //  * @test
    //  */
    // public function testCustomPageMethods()
    // {
    //     // Implementation will follow
    // }
}
