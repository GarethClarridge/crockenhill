<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Models\Meeting;
use App\Models\Page;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Sitemap\Contracts\Sitemapable;
use Spatie\Sitemap\Tags\Url;
use Tests\TestCase;

class SitemapableTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sermon_implements_sitemapable_interface(): void
    {
        $sermon = new Sermon;

        $this->assertInstanceOf(
            Sitemapable::class,
            $sermon,
            'Sermon model should implement Sitemapable interface'
        );
    }

    #[Test]
    public function page_implements_sitemapable_interface(): void
    {
        $page = new Page;

        $this->assertInstanceOf(
            Sitemapable::class,
            $page,
            'Page model should implement Sitemapable interface'
        );
    }

    #[Test]
    public function meeting_implements_sitemapable_interface(): void
    {
        $meeting = new Meeting;

        $this->assertInstanceOf(
            Sitemapable::class,
            $meeting,
            'Meeting model should implement Sitemapable interface'
        );
    }

    #[Test]
    public function sermon_to_sitemap_tag_returns_url_object(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'date' => '2024-01-15',
        ]);

        $tag = $sermon->toSitemapTag();

        $this->assertInstanceOf(Url::class, $tag);
    }

    #[Test]
    public function sermon_sitemap_tag_uses_date_based_url_format(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'date' => '2024-03-15',
        ]);

        $tag = $sermon->toSitemapTag();

        $this->assertStringContainsString(
            '/christ/sermons/2024/03/test-sermon',
            $tag->url
        );
    }

    #[Test]
    public function recent_sermon_has_high_priority(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'recent-sermon',
            'date' => now()->subDays(15),
        ]);

        $tag = $sermon->toSitemapTag();

        $this->assertEquals(0.8, $tag->priority);
    }

    #[Test]
    public function old_sermon_has_lower_priority(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'old-sermon',
            'date' => now()->subDays(60), // 60 days old should have lower priority than < 30 days
        ]);

        $tag = $sermon->toSitemapTag();

        $this->assertEquals(0.6, $tag->priority);
    }

    #[Test]
    public function recent_sermon_has_monthly_change_frequency(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'recent-sermon',
            'date' => now()->subDays(100),
        ]);

        $tag = $sermon->toSitemapTag();

        $this->assertEquals('monthly', $tag->changeFrequency);
    }

    #[Test]
    public function old_sermon_has_yearly_change_frequency(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'old-sermon',
            'date' => now()->subYears(2), // 2 years old should have yearly frequency
        ]);

        $tag = $sermon->toSitemapTag();

        $this->assertEquals('yearly', $tag->changeFrequency);
    }

    #[Test]
    public function sermon_sitemap_tag_includes_last_modification_date(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'date' => '2024-01-15',
        ]);

        $tag = $sermon->toSitemapTag();

        $this->assertNotNull($tag->lastModificationDate);
    }

    #[Test]
    public function page_to_sitemap_tag_returns_url_object(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-page',
            'area' => 'church',
        ]);

        $tag = $page->toSitemapTag();

        $this->assertInstanceOf(Url::class, $tag);
    }

    #[Test]
    public function page_sitemap_tag_uses_route_attribute(): void
    {
        $page = Page::factory()->create([
            'slug' => 'about-us',
            'area' => 'church',
        ]);

        $tag = $page->toSitemapTag();

        $this->assertStringContainsString('/church/about-us', $tag->url);
    }

    #[Test]
    public function page_has_correct_priority(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-page',
            'area' => 'church',
        ]);

        $tag = $page->toSitemapTag();

        $this->assertEquals(0.7, $tag->priority);
    }

    #[Test]
    public function page_has_monthly_change_frequency(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-page',
            'area' => 'church',
        ]);

        $tag = $page->toSitemapTag();

        $this->assertEquals('monthly', $tag->changeFrequency);
    }

    #[Test]
    public function page_sitemap_tag_handles_null_updated_at(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-page',
            'area' => 'church',
        ]);

        // Set updated_at to null on the in-memory model to test null guard in presenter
        $page->updated_at = null;

        $tag = $page->toSitemapTag();

        // Should not throw error and return valid Url object
        $this->assertInstanceOf(Url::class, $tag);
    }

    #[Test]
    public function meeting_to_sitemap_tag_returns_url_object(): void
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'test-meeting',
        ]);

        $tag = $meeting->toSitemapTag();

        $this->assertInstanceOf(Url::class, $tag);
    }

    #[Test]
    public function meeting_sitemap_tag_uses_community_url_format(): void
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'test-community-meeting',
        ]);

        $tag = $meeting->toSitemapTag();

        $this->assertStringContainsString('/community/test-community-meeting', $tag->url);
    }

    #[Test]
    public function meeting_has_correct_priority(): void
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'test-meeting',
        ]);

        $tag = $meeting->toSitemapTag();

        $this->assertEquals(0.6, $tag->priority);
    }

    #[Test]
    public function meeting_has_monthly_change_frequency(): void
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'test-meeting',
        ]);

        $tag = $meeting->toSitemapTag();

        $this->assertEquals('monthly', $tag->changeFrequency);
    }

    #[Test]
    public function meeting_sitemap_tag_handles_null_updated_at(): void
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'test-meeting',
        ]);

        // Set updated_at to null on the in-memory model to test null guard in presenter
        $meeting->updated_at = null;

        $tag = $meeting->toSitemapTag();

        // Should not throw error and return valid Url object
        $this->assertInstanceOf(Url::class, $tag);
    }
}
