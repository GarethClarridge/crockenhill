<?php

namespace Tests\Feature;

use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RichArticleMetadataTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sermon_page_includes_rich_article_metadata(): void
    {
        $preacher = Preacher::factory()->create([
            'name' => 'John Doe',
            'slug' => 'john-doe',
        ]);

        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon',
            'slug' => 'test-sermon',
            'date' => '2025-03-15',
            'preacher_id' => $preacher->id,
            'series' => 'Test Series',
        ]);

        $response = $this->get('/christ/sermons/2025/03/test-sermon');

        $response->assertStatus(200);

        // Check Open Graph Article Metadata
        $response->assertSee('<meta property="og:type" content="article">', false);
        $response->assertSee('<meta property="article:author" content="http://localhost/christ/sermons/preachers/john-doe">', false);
        $response->assertSee('<meta property="article:published_time" content="2025-03-15T00:00:00+00:00">', false);
        $response->assertSee('<meta property="article:section" content="Sermons">', false);
        $response->assertSee('<meta property="article:tag" content="Test Series">', false);

        // Check Schema.org Linking
        $response->assertSee('"publisher":', false);
        $response->assertSee('"@id": "http:\/\/localhost"', false);
    }
}
