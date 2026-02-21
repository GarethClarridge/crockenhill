<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HomepageContentTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    #[Test]
    public function homepage_has_improved_welcome_and_specific_calls_to_action(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Welcome');
        $response->assertSee('is a friendly, Bible-teaching church in the village of Crockenhill');
        $response->assertDontSee('is friendly, Bible teaching church in');
        $response->assertSee('Learn about Sunday evenings');
        $response->assertSee('Learn about Bible study');
        $response->assertSee('Explore the good news about Jesus');
    }

    #[Test]
    public function homepage_has_single_set_of_hero_navigation_links(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);

        $content = (string) $response->getContent();

        $this->assertSame(1, substr_count($content, 'href="#worshipping-god"'));
        $this->assertSame(1, substr_count($content, 'href="#strengthening-believers"'));
        $this->assertSame(1, substr_count($content, 'href="#proclaiming-jesus-christ-to-all"'));
    }
}
