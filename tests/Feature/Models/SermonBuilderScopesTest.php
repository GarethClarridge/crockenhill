<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Enums\SermonSourceType;
use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonBuilderScopesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function scope_by_preacher_finds_by_denormalized_name_or_profile_relationship(): void
    {
        // 1. Match via denormalized name column (even if preacher_id is null)
        $sermonByDenormalized = Sermon::factory()->byPreacher('Denormalized Name')->create([
            'preacher_id' => null,
        ]);

        $this->assertTrue(Sermon::byPreacher('Denormalized Name')->get()->contains($sermonByDenormalized));

        // 2. Match via linked preacher profile name (even if denormalized column is stale)
        $preacher = Preacher::factory()->create(['name' => 'Profile Name']);

        // We use createQuietly to bypass the SermonIdentityObserver which would otherwise
        // sync the 'preacher' string back to the profile name on save.
        $sermonWithProfile = Sermon::factory()->withPreacher($preacher)->createQuietly([
            'preacher' => 'Stale Name',
        ]);

        // Test matching via denormalized column
        $this->assertTrue(Sermon::byPreacher('Stale Name')->get()->contains($sermonWithProfile));

        // Test matching via profile relationship
        $this->assertTrue(Sermon::byPreacher('Profile Name')->get()->contains($sermonWithProfile));

        // Verify no false positives
        $this->assertFalse(Sermon::byPreacher('Profile Name')->get()->contains($sermonByDenormalized));
    }

    #[Test]
    public function scope_by_source_type_filters_correctly(): void
    {
        $manual = Sermon::factory()->create(['source_type' => SermonSourceType::Manual]);
        $livestream = Sermon::factory()->create(['source_type' => SermonSourceType::Livestream]);

        $results = Sermon::bySourceType(SermonSourceType::Manual)->get();

        $this->assertTrue($results->contains($manual));
        $this->assertFalse($results->contains($livestream));
    }

    #[Test]
    public function scope_from_livestream_filters_correctly(): void
    {
        $manual = Sermon::factory()->create(['source_type' => SermonSourceType::Manual]);
        $livestream = Sermon::factory()->create(['source_type' => SermonSourceType::Livestream]);

        $results = Sermon::fromLivestream()->get();

        $this->assertFalse($results->contains($manual));
        $this->assertTrue($results->contains($livestream));
    }

    #[Test]
    public function scope_with_video_filters_correctly(): void
    {
        $withVideo = Sermon::factory()->create(['video_file_path' => 'videos/sermon.mp4']);
        $withoutVideo = Sermon::factory()->create(['video_file_path' => null]);

        $results = Sermon::withVideo()->get();

        $this->assertTrue($results->contains($withVideo));
        $this->assertFalse($results->contains($withoutVideo));
    }

    #[Test]
    public function scope_with_thumbnail_filters_correctly(): void
    {
        $withThumbnail = Sermon::factory()->create(['thumbnail_file_path' => 'thumbnails/sermon.webp']);
        $withoutThumbnail = Sermon::factory()->create(['thumbnail_file_path' => null]);

        $results = Sermon::withThumbnail()->get();

        $this->assertTrue($results->contains($withThumbnail));
        $this->assertFalse($results->contains($withoutThumbnail));
    }

    #[Test]
    public function scope_needs_preacher_review_filters_correctly(): void
    {
        $needsReview = Sermon::factory()->create(['needs_preacher_review' => true]);
        $noReview = Sermon::factory()->create(['needs_preacher_review' => false]);

        $results = Sermon::needsPreacherReview()->get();

        $this->assertTrue($results->contains($needsReview));
        $this->assertFalse($results->contains($noReview));
    }

    #[Test]
    public function scope_for_podcast_filters_and_orders_correctly(): void
    {
        $older = Sermon::factory()->create([
            'audio_file_path' => 'audio/older.mp3',
            'date' => now()->subDays(2),
        ]);
        $newer = Sermon::factory()->create([
            'audio_file_path' => 'audio/newer.mp3',
            'date' => now()->subDay(),
        ]);
        $noAudio = Sermon::factory()->create([
            'audio_file_path' => null,
            'date' => now(),
        ]);

        $results = Sermon::forPodcast()->get();

        $this->assertCount(2, $results);
        $this->assertEquals($newer->id, $results[0]->id);
        $this->assertEquals($older->id, $results[1]->id);
        $this->assertFalse($results->contains($noAudio));
    }
}
