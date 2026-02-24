<?php

namespace Tests\Feature\Api;

use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SermonApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the storage disk
        Storage::fake('public');

        // Clear any existing sermons to ensure test isolation
        Sermon::query()->delete();
    }

    public function test_can_list_sermons_via_api(): void
    {
        // Create test sermons
        $sermons = Sermon::factory()->count(3)->create();

        $response = $this->getJson('/api/sermons');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'slug',
                        'date',
                        'human_date',
                        'service',
                        'preacher',
                        'series',
                        'reference',
                        'points',
                        'audio_url',
                        'thumbnail_url',
                        'series_url',
                        'preacher_url',
                    ],
                ],
                'links',
                'meta',
            ]);

        $this->assertCount(3, $response->json('data'));
    }

    public function test_can_show_individual_sermon_via_api(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon',
            'thumbnail_file_path' => 'sermons/thumbnails/test-thumbnail.jpg',
        ]);

        // Create a fake thumbnail file
        Storage::disk('public')->put('sermons/thumbnails/test-thumbnail.jpg', 'fake image content');

        $response = $this->getJson("/api/sermons/{$sermon->id}");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $sermon->id,
                    'title' => 'Test Sermon',
                    'thumbnail_url' => Storage::disk('public')->url('sermons/thumbnails/test-thumbnail.jpg'),
                ],
            ]);
    }

    public function test_api_includes_preacher_details_image_url_when_preacher_is_loaded(): void
    {
        $preacher = Preacher::factory()->create([
            'name' => 'Test Preacher',
            'slug' => 'test-preacher',
            'image_path' => 'preachers/test-preacher.jpg',
        ]);

        $sermon = Sermon::factory()->create([
            'preacher' => 'Test Preacher',
            'preacher_id' => $preacher->id,
        ]);

        $response = $this->getJson("/api/sermons/{$sermon->id}");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'preacher_details' => [
                        'id' => $preacher->id,
                        'name' => 'Test Preacher',
                        'slug' => 'test-preacher',
                        'image_url' => Storage::disk('public')->url('preachers/test-preacher.jpg'),
                    ],
                ],
            ]);
    }

    public function test_thumbnail_url_is_null_when_no_thumbnail_exists(): void
    {
        $sermon = Sermon::factory()->create([
            'thumbnail_file_path' => null,
        ]);

        $response = $this->getJson("/api/sermons/{$sermon->id}");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'thumbnail_url' => null,
                ],
            ]);
    }

    public function test_can_filter_sermons_with_thumbnails(): void
    {
        // Create sermons with and without thumbnails
        $sermonWithThumbnail = Sermon::factory()->create([
            'thumbnail_file_path' => 'sermons/thumbnails/test-thumbnail.jpg',
        ]);

        $sermonWithoutThumbnail = Sermon::factory()->create([
            'thumbnail_file_path' => null,
        ]);

        $response = $this->getJson('/api/sermons?with_thumbnail=1');

        $response->assertStatus(200);

        $sermonIds = collect($response->json('data'))->pluck('id')->toArray();

        $this->assertContains($sermonWithThumbnail->id, $sermonIds);
        $this->assertNotContains($sermonWithoutThumbnail->id, $sermonIds);
    }

    public function test_can_filter_sermons_by_service(): void
    {
        $morningSermon = Sermon::factory()->create(['service' => 'morning']);
        $eveningSermon = Sermon::factory()->create(['service' => 'evening']);

        $response = $this->getJson('/api/sermons?service=morning');

        $response->assertStatus(200);

        $sermonIds = collect($response->json('data'))->pluck('id')->toArray();

        $this->assertContains($morningSermon->id, $sermonIds);
        $this->assertNotContains($eveningSermon->id, $sermonIds);
    }

    public function test_api_includes_thumbnail_metadata_when_available(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon with Metadata',
            'thumbnail_file_path' => 'sermons/thumbnails/test-thumbnail.jpg',
            'thumbnail_metadata' => [
                'width' => 1280,
                'height' => 720,
                'size' => 'web',
                'generated_at' => '2024-01-15 10:30:00',
            ],
        ]);

        // Create a fake thumbnail file
        Storage::disk('public')->put('sermons/thumbnails/test-thumbnail.jpg', 'fake image content');

        $response = $this->getJson("/api/sermons/{$sermon->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'thumbnail_url',
                    'thumbnail_metadata' => [
                        'width',
                        'height',
                        'size',
                        'generated_at',
                    ],
                ],
            ])
            ->assertJson([
                'data' => [
                    'thumbnail_metadata' => [
                        'width' => 1280,
                        'height' => 720,
                        'size' => 'web',
                    ],
                ],
            ]);
    }

    public function test_api_handles_missing_thumbnail_metadata_gracefully(): void
    {
        $sermon = Sermon::factory()->create([
            'thumbnail_file_path' => null,
            'thumbnail_metadata' => null,
        ]);

        $response = $this->getJson("/api/sermons/{$sermon->id}");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'thumbnail_url' => null,
                    'thumbnail_metadata' => null,
                ],
            ]);
    }

    public function test_api_pagination_includes_thumbnail_data(): void
    {
        // Create sermons with and without thumbnails
        Sermon::factory()->count(5)->create([
            'thumbnail_file_path' => 'sermons/thumbnails/test-thumbnail.jpg',
        ]);

        Sermon::factory()->count(3)->create([
            'thumbnail_file_path' => null,
        ]);

        // Create fake thumbnail files
        Storage::disk('public')->put('sermons/thumbnails/test-thumbnail.jpg', 'fake image content');

        $response = $this->getJson('/api/sermons?per_page=3');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'thumbnail_url',
                    ],
                ],
                'meta' => [
                    'current_page',
                    'per_page',
                    'total',
                ],
            ]);

        $this->assertCount(3, $response->json('data'));
        $this->assertEquals(8, $response->json('meta.total'));
    }

    public function test_per_page_is_clamped_to_maximum_of_100(): void
    {
        Sermon::factory()->count(5)->create();

        $response = $this->getJson('/api/sermons?per_page=999');

        $response->assertStatus(200);
        $this->assertLessThanOrEqual(100, $response->json('meta.per_page'));
    }

    public function test_per_page_is_clamped_to_minimum_of_1(): void
    {
        Sermon::factory()->count(3)->create();

        $response = $this->getJson('/api/sermons?per_page=0');

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $response->json('meta.per_page'));
    }

    public function test_api_search_functionality_with_thumbnails(): void
    {
        $sermonWithThumbnail = Sermon::factory()->create([
            'title' => 'Searchable Sermon Title',
            'thumbnail_file_path' => 'sermons/thumbnails/searchable.jpg',
        ]);

        $sermonWithoutThumbnail = Sermon::factory()->create([
            'title' => 'Another Searchable Title',
            'thumbnail_file_path' => null,
        ]);

        // Create fake thumbnail file
        Storage::disk('public')->put('sermons/thumbnails/searchable.jpg', 'fake image content');

        $response = $this->getJson('/api/sermons?search=Searchable');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(2, $data);

        // Find the sermon with thumbnail
        $sermonData = collect($data)->firstWhere('id', $sermonWithThumbnail->id);
        $this->assertNotNull($sermonData['thumbnail_url']);

        // Find the sermon without thumbnail
        $sermonData = collect($data)->firstWhere('id', $sermonWithoutThumbnail->id);
        $this->assertNull($sermonData['thumbnail_url']);
    }

    public function test_api_sorting_works_with_thumbnail_data(): void
    {
        $oldSermon = Sermon::factory()->create([
            'date' => '2023-01-01',
            'title' => 'Old Sermon',
            'thumbnail_file_path' => 'sermons/thumbnails/old.jpg',
        ]);

        $newSermon = Sermon::factory()->create([
            'date' => '2024-01-01',
            'title' => 'New Sermon',
            'thumbnail_file_path' => null,
        ]);

        // Create fake thumbnail file
        Storage::disk('public')->put('sermons/thumbnails/old.jpg', 'fake image content');

        $response = $this->getJson('/api/sermons?sort=date&order=desc');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertGreaterThanOrEqual(2, count($data));

        // First sermon should be the newer one
        $firstSermon = $data[0];
        $this->assertEquals($newSermon->id, $firstSermon['id']);
        $this->assertNull($firstSermon['thumbnail_url']);

        // Find the older sermon in results
        $olderSermonData = collect($data)->firstWhere('id', $oldSermon->id);
        $this->assertNotNull($olderSermonData);
        $this->assertNotNull($olderSermonData['thumbnail_url']);
    }

    public function test_api_includes_thumbnail_data_in_response(): void
    {
        $sermon = Sermon::factory()->create([
            'thumbnail_file_path' => 'sermons/thumbnails/cache-test.jpg',
        ]);

        // Create a fake thumbnail file
        Storage::disk('public')->put('sermons/thumbnails/cache-test.jpg', 'fake image content');

        $response = $this->getJson("/api/sermons/{$sermon->id}");

        $response->assertStatus(200);

        // Check that the response includes thumbnail data
        $data = $response->json('data');
        $this->assertNotNull($data['thumbnail_url']);
        $this->assertStringContainsString('cache-test.jpg', $data['thumbnail_url']);
    }

    public function test_api_handles_concurrent_requests_with_thumbnails(): void
    {
        // Create multiple sermons with thumbnails
        $sermons = Sermon::factory()->count(10)->create([
            'thumbnail_file_path' => 'sermons/thumbnails/concurrent-test.jpg',
        ]);

        // Create fake thumbnail file
        Storage::disk('public')->put('sermons/thumbnails/concurrent-test.jpg', 'fake image content');

        // Make multiple concurrent requests
        $responses = [];
        foreach ($sermons->take(3) as $sermon) {
            $responses[] = $this->getJson("/api/sermons/{$sermon->id}");
        }

        // All responses should be successful
        foreach ($responses as $response) {
            $response->assertStatus(200)
                ->assertJsonStructure([
                    'data' => [
                        'thumbnail_url',
                    ],
                ]);
        }
    }
}
