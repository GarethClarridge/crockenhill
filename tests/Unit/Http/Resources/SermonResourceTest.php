<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Resources;

use App\Http\Resources\SermonResource;
use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonResourceTest extends TestCase
{
    use DatabaseTransactions;

    private array $sermonViewData = [
        'audio_url' => 'https://example.com/audio.mp3',
        'canonical_url' => 'https://example.com/canonical',
        'preacher_url' => 'https://example.com/preacher',
        'public_url' => 'https://example.com/public',
        'thumbnail_url' => 'https://example.com/thumbnail.jpg',
        'transcript' => 'https://example.com/transcript',
        'video_url' => 'https://example.com/video.mp4',
    ];

    #[Test]
    public function it_transforms_to_array_with_precomputed_sermon_view(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon',
            'show_points' => true,
            'points' => ['Point 1', 'Point 2'],
        ]);
        $sermon->setAttribute('sermon_view', $this->sermonViewData);

        $resource = new SermonResource($sermon);
        $array = $resource->toArray(request());

        $this->assertEquals($sermon->id, $array['id']);
        $this->assertEquals('Test Sermon', $array['title']);
        $this->assertEquals($this->sermonViewData['audio_url'], $array['audio_url']);
        $this->assertEquals($this->sermonViewData['video_url'], $array['video_url']);
        $this->assertEquals($this->sermonViewData['thumbnail_url'], $array['thumbnail_url']);
        $this->assertEquals(['Point 1', 'Point 2'], $array['points']);
    }

    #[Test]
    public function it_throws_logic_exception_if_sermon_view_is_missing(): void
    {
        $sermon = Sermon::factory()->create();

        $resource = new SermonResource($sermon);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('SermonResource requires precomputed sermon_view data.');

        $resource->toArray(request());
    }

    #[Test]
    public function it_includes_preacher_details_when_loaded(): void
    {
        $preacher = Preacher::factory()->create([
            'name' => 'John Smith Resource Test',
            'slug' => 'john-smith-resource-test',
            'image_path' => 'preachers/john.jpg',
        ]);
        $sermon = Sermon::factory()->create(['preacher_id' => $preacher->id]);
        $sermon->load('preacherProfile');
        $sermon->setAttribute('sermon_view', $this->sermonViewData);

        Storage::fake('public');

        $resource = new SermonResource($sermon);
        $array = $resource->toArray(request());

        $this->assertArrayHasKey('preacher_details', $array);
        $this->assertNotInstanceOf(\Illuminate\Http\Resources\MissingValue::class, $array['preacher_details']);
        $this->assertEquals($preacher->id, $array['preacher_details']['id']);
        $this->assertEquals('John Smith Resource Test', $array['preacher_details']['name']);
        $this->assertStringContainsString('preachers/john.jpg', $array['preacher_details']['image_url']);
    }

    #[Test]
    public function it_omits_preacher_details_when_not_loaded(): void
    {
        $sermon = Sermon::factory()->create();
        $sermon->setAttribute('sermon_view', $this->sermonViewData);

        $resource = new SermonResource($sermon);
        $array = $resource->toArray(request());

        $this->assertInstanceOf(\Illuminate\Http\Resources\MissingValue::class, $array['preacher_details']);
    }

    #[Test]
    public function it_handles_absolute_preacher_image_urls(): void
    {
        $preacher = Preacher::factory()->create([
            'image_path' => 'https://external.com/image.jpg',
        ]);
        $sermon = Sermon::factory()->create(['preacher_id' => $preacher->id]);
        $sermon->load('preacherProfile');
        $sermon->setAttribute('sermon_view', $this->sermonViewData);

        $resource = new SermonResource($sermon);
        $array = $resource->toArray(request());

        $this->assertEquals('https://external.com/image.jpg', $array['preacher_details']['image_url']);
    }

    #[Test]
    public function it_handles_root_relative_preacher_image_urls(): void
    {
        $preacher = Preacher::factory()->create([
            'image_path' => '/local/image.jpg',
        ]);
        $sermon = Sermon::factory()->create(['preacher_id' => $preacher->id]);
        $sermon->load('preacherProfile');
        $sermon->setAttribute('sermon_view', $this->sermonViewData);

        $resource = new SermonResource($sermon);
        $array = $resource->toArray(request());

        $this->assertEquals('/local/image.jpg', $array['preacher_details']['image_url']);
    }

    #[Test]
    public function it_omits_points_when_show_points_is_false(): void
    {
        $sermon = Sermon::factory()->create([
            'show_points' => false,
            'points' => ['Secret Point'],
        ]);
        $sermon->setAttribute('sermon_view', $this->sermonViewData);

        $resource = new SermonResource($sermon);
        $array = $resource->toArray(request());

        $this->assertInstanceOf(\Illuminate\Http\Resources\MissingValue::class, $array['points']);
    }

    #[Test]
    public function it_filters_thumbnail_metadata_to_public_fields(): void
    {
        $metadata = [
            'timestamp' => 123.45,
            'video_duration' => 3600,
            'video_resolution' => ['width' => 1920, 'height' => 1080],
            'thumbnail_sizes' => [],
            'generated_at' => '2026-01-01 12:00:00',
            'width' => 1920,
            'height' => 1080,
            'size' => 1024,
            'generation_info' => [],
            'file_info' => [],
            'formats' => [],
            'internal_secret' => 'do not expose',
        ];

        $sermon = Sermon::factory()->create([
            'thumbnail_metadata' => $metadata,
        ]);
        $sermon->setAttribute('sermon_view', $this->sermonViewData);

        $resource = new SermonResource($sermon);
        $array = $resource->toArray(request());

        $this->assertArrayHasKey('thumbnail_metadata', $array);
        $this->assertEquals(123.45, $array['thumbnail_metadata']['timestamp']);
        $this->assertArrayNotHasKey('internal_secret', $array['thumbnail_metadata']);

        // Check a few more public fields
        $this->assertEquals(3600, $array['thumbnail_metadata']['video_duration']);
        $this->assertEquals(1920, $array['thumbnail_metadata']['width']);
    }

    #[Test]
    public function it_returns_null_thumbnail_metadata_when_missing(): void
    {
        $sermon = Sermon::factory()->create([
            'thumbnail_metadata' => null,
        ]);
        $sermon->setAttribute('sermon_view', $this->sermonViewData);

        $resource = new SermonResource($sermon);
        $array = $resource->toArray(request());

        $this->assertNull($array['thumbnail_metadata']);
    }
}
