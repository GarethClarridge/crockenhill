<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonSocialMetadataTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function sermon_page_renders_twitter_labels_for_preacher_and_series(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'John Owen']);
        $sermon = Sermon::factory()->create([
            'title' => 'Social Metadata Sermon',
            'slug' => 'social-metadata-sermon',
            'preacher_id' => $preacher->id,
            'preacher' => $preacher->name,
            'series' => 'Great Doctrines',
            'date' => '2024-03-15',
        ]);

        $response = $this->followingRedirects()->get("/christ/sermons/2024/03/{$sermon->slug}");

        $response->assertStatus(200);
        $response->assertSee('<meta name="twitter:label1" content="Preacher">', false);
        $response->assertSee('<meta name="twitter:data1" content="John Owen">', false);
        $response->assertSee('<meta name="twitter:label2" content="Series">', false);
        $response->assertSee('<meta name="twitter:data2" content="Great Doctrines">', false);
    }

    #[Test]
    public function sermon_page_omits_series_label_when_no_series_is_present(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Charles Spurgeon']);
        $sermon = Sermon::factory()->create([
            'title' => 'No Series Sermon',
            'slug' => 'no-series-sermon',
            'preacher_id' => $preacher->id,
            'preacher' => $preacher->name,
            'series' => null,
            'date' => '2024-03-15',
        ]);

        $response = $this->followingRedirects()->get("/christ/sermons/2024/03/{$sermon->slug}");

        $response->assertStatus(200);
        $response->assertSee('<meta name="twitter:label1" content="Preacher">', false);
        $response->assertSee('<meta name="twitter:data1" content="Charles Spurgeon">', false);
        $response->assertDontSee('twitter:label2');
        $response->assertDontSee('twitter:data2');
    }
}
