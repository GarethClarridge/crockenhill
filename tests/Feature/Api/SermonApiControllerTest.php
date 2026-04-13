<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Enums\SermonVideoQualityStatus;
use App\Models\Preacher;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
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
            'service' => SermonService::Morning,
            'content_type' => SermonContentType::Sermon,
        ]);
        Sermon::factory()->create([
            'title' => 'Evening Sermon',
            'service' => SermonService::Evening,
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

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['per_page']);
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

    #[Test]
    public function api_show_does_not_return_video_url_for_rejected_videos(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        Storage::disk('public')->put('sermons/video.mp4', 'video');

        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::Sermon,
            'video_file_path' => 'sermons/video.mp4',
            'video_quality_status' => SermonVideoQualityStatus::Rejected,
        ]);

        $this->getJson("/api/sermons/{$sermon->id}")
            ->assertOk()
            ->assertJsonPath('data.video_url', null);
    }

    // ── Search ─────────────────────────────────────────────────────────────

    #[Test]
    public function api_index_searches_by_title(): void
    {
        Sermon::factory()->create(['title' => 'Finding Hope']);
        Sermon::factory()->create(['title' => 'Lost in Wilderness']);

        $response = $this->getJson('/api/sermons?search=Hope');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Finding Hope', $response->json('data.0.title'));
    }

    #[Test]
    public function api_index_searches_by_preacher_string(): void
    {
        Sermon::factory()->create(['preacher' => 'John Bunyan']);
        Sermon::factory()->create(['preacher' => 'Charles Spurgeon']);

        $response = $this->getJson('/api/sermons?search=Bunyan');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('John Bunyan', $response->json('data.0.preacher'));
    }

    #[Test]
    public function api_index_searches_by_preacher_profile_name(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Alistair Begg']);
        Sermon::factory()->create(['preacher_id' => $preacher->id, 'preacher' => 'A. Begg']);
        Sermon::factory()->create(['preacher' => 'Other Preacher']);

        $response = $this->getJson('/api/sermons?search=Alistair');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        // SermonResource uses displayPreacherName() which prefers profile name if loaded
        $this->assertSame('Alistair Begg', $response->json('data.0.preacher'));
    }

    #[Test]
    public function api_index_searches_by_series(): void
    {
        Sermon::factory()->create(['series' => 'Parables of Jesus']);
        Sermon::factory()->create(['series' => 'Life of David']);

        $response = $this->getJson('/api/sermons?search=Parables');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Parables of Jesus', $response->json('data.0.series'));
    }

    #[Test]
    public function api_index_searches_by_scripture_reference(): void
    {
        $passage = ScripturePassage::factory()->create([
            'display_reference' => 'Genesis 1:1',
            'normalized_reference' => 'Genesis 1:1',
        ]);
        Sermon::factory()->create(['scripture_passage_id' => $passage->id, 'reference' => 'Gen 1:1']);
        Sermon::factory()->create(['reference' => 'John 1:1']);

        $response = $this->getJson('/api/sermons?search=Genesis');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        // SermonResource uses displayReference() which prefers passage display_reference if loaded
        $this->assertSame('Genesis 1:1', $response->json('data.0.reference'));
    }

    #[Test]
    public function api_index_escapes_like_wildcards(): void
    {
        Sermon::factory()->create(['title' => '100% Grace']);
        Sermon::factory()->create(['title' => '1000 Reasons']);

        // Searching for '%' should only find the sermon with an actual '%' character,
        // not everything because '%' is a wildcard in LIKE.
        $response = $this->getJson('/api/sermons?search=%');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('100% Grace', $response->json('data.0.title'));
    }

    // ── Filtering ──────────────────────────────────────────────────────────

    #[Test]
    public function api_index_filters_by_preacher_string_directly(): void
    {
        Sermon::factory()->create(['preacher' => 'Exact Name']);
        Sermon::factory()->create(['preacher' => 'Another Name']);

        $response = $this->getJson('/api/sermons?preacher=Exact%20Name');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Exact Name', $response->json('data.0.preacher'));
    }

    #[Test]
    public function api_index_filters_by_preacher_id(): void
    {
        $preacher = Preacher::factory()->create();
        Sermon::factory()->create(['preacher_id' => $preacher->id]);
        Sermon::factory()->create(['preacher_id' => null]);

        $response = $this->getJson("/api/sermons?preacher_id={$preacher->id}");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($preacher->id, $response->json('data.0.preacher_id'));
    }

    #[Test]
    public function api_index_filters_by_with_thumbnail(): void
    {
        Sermon::factory()->create(['thumbnail_file_path' => 'thumbnails/sermon1.webp']);
        Sermon::factory()->create(['thumbnail_file_path' => null]);

        $response = $this->getJson('/api/sermons?with_thumbnail=1');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertNotNull($response->json('data.0.thumbnail_url'));
    }

    // ── Sorting ────────────────────────────────────────────────────────────

    #[Test]
    public function api_index_sorts_by_date_asc(): void
    {
        Sermon::factory()->create(['date' => '2024-01-01']);
        Sermon::factory()->create(['date' => '2023-01-01']);

        $response = $this->getJson('/api/sermons?sort=date&order=asc');

        $response->assertStatus(200);
        $this->assertSame('2023-01-01', $response->json('data.0.date'));
    }

    #[Test]
    public function api_index_sorts_by_title_desc(): void
    {
        Sermon::factory()->create(['title' => 'Alpha']);
        Sermon::factory()->create(['title' => 'Omega']);

        $response = $this->getJson('/api/sermons?sort=title&order=desc');

        $response->assertStatus(200);
        $this->assertSame('Omega', $response->json('data.0.title'));
    }

    #[Test]
    public function api_index_sorts_by_preacher_name(): void
    {
        $preacherA = Preacher::factory()->create(['name' => 'Alistair']);
        $preacherB = Preacher::factory()->create(['name' => 'Zebedee']);

        Sermon::factory()->create(['preacher_id' => $preacherB->id, 'preacher' => 'Zebedee']);
        Sermon::factory()->create(['preacher_id' => $preacherA->id, 'preacher' => 'Alistair']);

        $response = $this->getJson('/api/sermons?sort=preacher&order=asc');

        $response->assertStatus(200);
        $this->assertSame('Alistair', $response->json('data.0.preacher'));
    }
}
