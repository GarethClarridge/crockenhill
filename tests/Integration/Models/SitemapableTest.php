<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

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
        $this->assertInstanceOf(Sitemapable::class, new Sermon);
    }

    #[Test]
    public function sermon_to_sitemap_tag_returns_its_dated_url(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'date' => '2024-03-15',
        ]);

        $tag = $sermon->toSitemapTag();

        $this->assertInstanceOf(Url::class, $tag);
        $this->assertStringContainsString('/christ/sermons/2024/03/test-sermon', $tag->url);
    }

    #[Test]
    public function sermon_sitemap_tag_includes_last_modification_date(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'test-sermon',
            'date' => '2024-01-15',
        ]);

        $this->assertNotNull($sermon->toSitemapTag()->lastModificationDate);
    }
}
