<?php

namespace Tests\Feature;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoRegressionTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function sermon_page_has_a_valid_meta_description_and_it_is_not_empty()
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon',
            'date' => now(),
            'slug' => 'test-sermon',
        ]);

        $response = $this->get("/christ/sermons/{$sermon->date->format('Y')}/{$sermon->date->format('m')}/{$sermon->slug}");

        $response->assertStatus(200);

        // The current bug is that strip_tags(description) is used,
        // and description currently contains a <meta> tag, so it results in an empty string.
        $html = $response->getContent();

        // We expect to find a meta description tag with actual content
        $this->assertMatchesRegularExpression(
            '/<meta name="description" content="[^"]+">/',
            $html,
            'Meta description should not be empty. Current HTML: '.$html
        );
    }
}
