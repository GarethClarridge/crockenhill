<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonApiControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Sermon::query()->delete();
    }

    // ── index ──────────────────────────────────────────────────────────────

    #[Test]
    public function api_index_returns_200_with_json(): void
    {
        $response = $this->getJson('/api/sermons');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data', 'meta']);
    }

    #[Test]
    public function api_index_returns_only_regular_sermons_not_childrens_talks(): void
    {
        Sermon::factory()->create([
            'title' => 'Regular Sermon',
            'content_type' => SermonContentType::Sermon,
        ]);

        Sermon::factory()->create([
            'title' => 'Childrens Talk',
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        $response = $this->getJson('/api/sermons');

        $response->assertStatus(200);
        $data = $response->json('data');
        $titles = array_column($data, 'title');

        $this->assertContains('Regular Sermon', $titles);
        $this->assertNotContains('Childrens Talk', $titles);
    }

    #[Test]
    public function api_index_filters_by_service(): void
    {
        Sermon::factory()->create([
            'title' => 'Morning Sermon',
            'service' => SermonService::MORNING,
            'content_type' => SermonContentType::Sermon,
        ]);
        Sermon::factory()->create([
            'title' => 'Evening Sermon',
            'service' => SermonService::EVENING,
            'content_type' => SermonContentType::Sermon,
        ]);

        $response = $this->getJson('/api/sermons?service=morning');

        $response->assertStatus(200);
        $data = $response->json('data');
        $titles = array_column($data, 'title');

        $this->assertContains('Morning Sermon', $titles);
        $this->assertNotContains('Evening Sermon', $titles);
    }

    #[Test]
    public function api_index_filters_by_series(): void
    {
        Sermon::factory()->create([
            'title' => 'In Series',
            'series' => 'Faith',
            'content_type' => SermonContentType::Sermon,
        ]);
        Sermon::factory()->create([
            'title' => 'Not In Series',
            'series' => null,
            'content_type' => SermonContentType::Sermon,
        ]);

        $response = $this->getJson('/api/sermons?series=Faith');

        $response->assertStatus(200);
        $data = $response->json('data');
        $titles = array_column($data, 'title');

        $this->assertContains('In Series', $titles);
        $this->assertNotContains('Not In Series', $titles);
    }

    #[Test]
    public function api_index_respects_per_page_parameter(): void
    {
        Sermon::factory()->count(5)->create(['content_type' => SermonContentType::Sermon]);

        $response = $this->getJson('/api/sermons?per_page=2');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    #[Test]
    public function api_index_per_page_is_capped_at_100(): void
    {
        Sermon::factory()->count(5)->create(['content_type' => SermonContentType::Sermon]);

        $response = $this->getJson('/api/sermons?per_page=999');

        $response->assertStatus(200);
        $this->assertLessThanOrEqual(100, $response->json('meta.per_page'));
    }

    // ── show ───────────────────────────────────────────────────────────────

    #[Test]
    public function api_show_returns_sermon_by_id(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Specific Sermon',
            'content_type' => SermonContentType::Sermon,
        ]);

        $response = $this->getJson("/api/sermons/{$sermon->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.title', 'Specific Sermon');
    }

    #[Test]
    public function api_show_returns_404_for_nonexistent_sermon(): void
    {
        $response = $this->getJson('/api/sermons/999999');

        $response->assertStatus(404);
    }

    #[Test]
    public function api_show_returns_404_for_childrens_talk(): void
    {
        $talk = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        $response = $this->getJson("/api/sermons/{$talk->id}");

        $response->assertStatus(404);
    }
}
