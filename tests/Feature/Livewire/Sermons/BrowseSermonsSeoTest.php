<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Preacher;
use App\Models\Sermon;
use App\Services\SermonScriptureFilterIndexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BrowseSermonsSeoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sermon_archive_shows_dynamic_seo_tags(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'SEO Expert', 'slug' => 'seo-expert']);
        $sermon = Sermon::factory()->create([
            'title' => 'SEO Sermon',
            'preacher_id' => $preacher->id,
            'reference' => 'Genesis 1:1',
        ]);
        app(SermonScriptureFilterIndexService::class)->syncForSermon($sermon);

        $response = $this->get('/christ/sermons?book=Genesis&preacher='.$preacher->id);

        $response->assertStatus(200);
        $response->assertSee('<title>Sermons on Genesis by SEO Expert | Crockenhill Baptist Church</title>', false);
        $response->assertSee('<meta name="description" content="Browse our sermon archive covering Genesis preached by SEO Expert.">', false);
        $response->assertSee('<link rel="canonical" href="http://localhost/christ/sermons?book=Genesis&amp;preacher='.$preacher->id.'">', false);
        $response->assertSee('<meta property="og:title" content="Sermons on Genesis by SEO Expert | Crockenhill Baptist Church">', false);
    }
}
