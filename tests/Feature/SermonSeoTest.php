<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonContentType;
use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Wiring smoke tests for the structured-data (JSON-LD) blocks on sermon archive
 * pages, plus the legacy-route redirect.
 *
 * Archive title/description/canonical variants live in
 * SermonArchiveSeoPresenterTest; archive meta-tag wiring lives in
 * SermonBrowseSeoTest. This file proves the structured-data components render on
 * the archive page types: the ItemList on a listing, the Person schema on a
 * preacher profile, and the BreadcrumbList on a deep archive page.
 */
class SermonSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // These tests assert exact "numberOfItems" counts on the rendered SEO
        // JSON-LD, so they must start from an empty sermons table. A sibling
        // suite that commits rows outside RefreshDatabase's transaction (e.g. a
        // DatabaseMigrations DDL test) can otherwise leak sermons into the count.
        Sermon::query()->delete();
    }

    #[Test]
    public function sermon_index_renders_item_list_structured_data(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'John Doe']);
        Sermon::factory()->count(3)->create([
            'preacher' => 'John Doe',
            'preacher_id' => $preacher->id,
            'content_type' => SermonContentType::Sermon,
        ]);

        $response = $this->get(route('sermons.index'));

        $response->assertStatus(200);
        $response->assertSee('"@type": "ItemList"', false);
        $response->assertSee('"numberOfItems": 3', false);
        $response->assertSee('"@type": "Article"', false);
        $response->assertSee('John Doe');
    }

    #[Test]
    public function legacy_all_sermons_route_redirects_to_the_canonical_archive(): void
    {
        $response = $this->get(route('sermons.all'));

        $response->assertRedirect(route('sermons.index'));
        $response->assertStatus(301);
    }

    #[Test]
    public function preacher_archive_renders_item_list_and_breadcrumb_structured_data(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Preacher Name']);
        Sermon::factory()->count(2)->create([
            'preacher' => 'Preacher Name',
            'preacher_id' => $preacher->id,
            'content_type' => SermonContentType::Sermon,
        ]);

        $response = $this->get("/christ/sermons/preachers/{$preacher->slug}");

        $response->assertStatus(200);
        $response->assertSee('"@type": "ItemList"', false);
        $response->assertSee('"numberOfItems": 2', false);
        $response->assertSee('"@type": "BreadcrumbList"', false);
        $response->assertSee('"name": "Sermons"', false);
    }

    #[Test]
    public function preacher_profile_renders_person_structured_data(): void
    {
        $preacher = Preacher::factory()->create([
            'name' => 'Dr. Martin Lloyd-Jones',
            'bio' => 'A famous preacher with a long bio.',
            'image_path' => 'preachers/mlj.jpg',
        ]);

        $response = $this->get("/christ/sermons/preachers/{$preacher->slug}");

        $response->assertStatus(200);
        $response->assertSee('"@type": "Person"', false);
        $response->assertSee('"name": "Dr. Martin Lloyd-Jones"', false);
        $response->assertSee('"description": "A famous preacher with a long bio."', false);
        $response->assertSee('"image": "http://localhost/storage/preachers/mlj.jpg"', false);
        $response->assertSee('"worksFor": {', false);
    }
}
