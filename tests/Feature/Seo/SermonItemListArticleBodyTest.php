<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonItemListArticleBodyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function preacher_sermon_list_json_ld_includes_article_body_from_summary(): void
    {
        $preacher = Preacher::factory()->create();
        Sermon::factory()->create([
            'preacher_id' => $preacher->id,
            'summary' => 'This is a test sermon summary.',
            'show_summary' => true,
        ]);

        $response = $this->get(route('sermons.preacher', $preacher->slug));

        $response->assertStatus(200);
        $response->assertSee('"articleBody": "This is a test sermon summary."', false);
    }

    #[Test]
    public function series_sermon_list_json_ld_includes_article_body_from_summary(): void
    {
        $seriesName = 'Test Series';
        Sermon::factory()->create([
            'series' => $seriesName,
            'summary' => 'Series sermon summary.',
            'show_summary' => true,
        ]);

        $response = $this->get(route('sermons.series.show', Str::slug($seriesName)));

        $response->assertStatus(200);
        $response->assertSee('"articleBody": "Series sermon summary."', false);
    }
}
