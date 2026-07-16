<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Preacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wiring smoke tests for page types not covered elsewhere in the SEO suite:
 * the preachers index (ItemList of people) and the generic WebPage schema.
 *
 * Section-page meta tags are covered by SeoMetaTagsTest; sermon and sermon
 * archive meta tags by SermonBrowseSeoTest; archive title/description variants
 * by SermonArchiveSeoPresenterTest.
 */
class SeoMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_preachers_index_page_has_seo_metadata_and_json_ld(): void
    {
        Preacher::factory()->create(['name' => 'Preacher One', 'slug' => 'preacher-one', 'is_active' => true]);
        Preacher::factory()->create(['name' => 'Preacher Two', 'slug' => 'preacher-two', 'is_active' => true]);

        $response = $this->get('/christ/sermons/preachers');

        $response->assertStatus(200);
        $response->assertSee('Preachers | Crockenhill Baptist Church', false);
        $response->assertSee('Preachers at Crockenhill Baptist Church.', false);

        $content = $response->getContent();
        $this->assertStringContainsString('"@type": "ItemList"', $content);
        $this->assertStringContainsString('"name": "Preacher One"', $content);
        $this->assertStringContainsString('"name": "Preacher Two"', $content);
        $this->assertStringContainsString('"jobTitle": "Preacher"', $content);
    }

    public function test_pages_have_webpage_schema(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('"@type": "WebPage"', false);
        $response->assertSee('"name": "Crockenhill Baptist Church"', false);

        $logoSize = getimagesize(public_path('images/Primary.png'));
        if ($logoSize === false) {
            $this->fail('Primary logo image dimensions could not be read.');
        }

        $response->assertSee('"width": '.$logoSize[0], false);
        $response->assertSee('"height": '.$logoSize[1], false);

        $response = $this->get('/christ');
        $response->assertStatus(200);
        $response->assertSee('"@type": "WebPage"', false);
        $response->assertSee('"name": "Christ"', false);

        $response = $this->get('/christ/sermons');
        $response->assertStatus(200);
        $response->assertSee('"@type": "WebPage"', false);
        $response->assertSee('"name": "Sermons"', false);
    }
}
