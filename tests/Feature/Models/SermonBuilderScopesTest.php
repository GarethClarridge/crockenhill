<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Enums\SermonSourceType;
use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonBuilderScopesTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function scope_by_preacher_finds_by_denormalized_name_or_profile_relationship(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'John Profile']);

        $sermonByDenormalized = Sermon::factory()->byPreacher('John Denormalized')->create([
            'preacher_id' => null,
        ]);

        $sermonByProfile = Sermon::factory()->withPreacher($preacher)->create([
            'preacher' => 'Different Name', // Override the factory's name-sync to test specific query branch
        ]);

        $sermonUnrelated = Sermon::factory()->byPreacher('Someone Else')->create([
            'preacher_id' => null,
        ]);

        // Search by denormalized name
        $resultsDenormalized = Sermon::byPreacher('John Denormalized')->get();
        $this->assertTrue($resultsDenormalized->contains($sermonByDenormalized));
        $this->assertFalse($resultsDenormalized->contains($sermonByProfile));

        // Search by profile name
        $resultsProfile = Sermon::byPreacher('John Profile')->get();
        $this->assertTrue($resultsProfile->contains($sermonByProfile));
        $this->assertFalse($resultsProfile->contains($sermonByDenormalized));
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
