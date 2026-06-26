<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Enums\ProcessingStatus;
use App\Enums\SermonSourceType;
use App\Enums\SermonVideoQualityStatus;
use App\Enums\SermonVideoVisibilityOverride;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonStatusAndMediaTest extends TestCase
{
    use RefreshDatabase;

    // ---- Processing Status Helpers ----

    #[Test]
    public function it_returns_processing_status_from_latest_log(): void
    {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->create([
            'sermon_id' => $sermon->id,
            'status' => ProcessingStatus::Processing,
        ]);

        $this->assertSame(ProcessingStatus::Processing, $sermon->processingState()->status());
        $this->assertTrue($sermon->processingState()->log()->is($log));
    }

    #[Test]
    public function it_returns_null_processing_status_when_no_logs_exist(): void
    {
        $sermon = Sermon::factory()->create();

        $this->assertNull($sermon->processingState()->status());
        $this->assertNull($sermon->processingState()->log());
    }

    #[Test]
    public function it_reports_processing_completion_correctly(): void
    {
        $sermon = Sermon::factory()->create();

        $this->assertFalse($sermon->processingState()->isComplete());

        MediaProcessingLog::factory()->create([
            'sermon_id' => $sermon->id,
            'status' => ProcessingStatus::Completed,
        ]);

        $this->assertTrue($sermon->refresh()->processingState()->isComplete());
    }

    #[Test]
    public function it_reports_processing_failure_correctly(): void
    {
        $sermon = Sermon::factory()->create();

        $this->assertFalse($sermon->processingState()->isFailed());

        MediaProcessingLog::factory()->create([
            'sermon_id' => $sermon->id,
            'status' => ProcessingStatus::Failed,
        ]);

        $this->assertTrue($sermon->refresh()->processingState()->isFailed());
    }

    #[Test]
    public function it_reports_processing_in_progress_correctly(): void
    {
        $sermon = Sermon::factory()->create();

        $this->assertFalse($sermon->processingState()->isInProgress());

        MediaProcessingLog::factory()->create([
            'sermon_id' => $sermon->id,
            'status' => ProcessingStatus::Processing,
        ]);

        $this->assertTrue($sermon->refresh()->processingState()->isInProgress());
    }

    // ---- Media and Livestream Helpers ----

    #[Test]
    public function it_reports_if_it_is_from_livestream(): void
    {
        $livestreamSermon = Sermon::factory()->create(['source_type' => SermonSourceType::Livestream]);
        $manualSermon = Sermon::factory()->create(['source_type' => SermonSourceType::Manual]);

        $this->assertTrue($livestreamSermon->isFromLivestream());
        $this->assertFalse($manualSermon->isFromLivestream());
    }

    #[Test]
    public function it_reports_if_it_has_video(): void
    {
        $withVideo = Sermon::factory()->create(['video_file_path' => 'sermons/video.mp4']);
        $withoutVideo = Sermon::factory()->create(['video_file_path' => null]);

        $this->assertTrue($withVideo->hasVideo());
        $this->assertFalse($withoutVideo->hasVideo());
    }

    #[Test]
    public function it_returns_video_quality_status_with_fallback(): void
    {
        // Columns are NOT NULL with defaults, so we test with explicit values
        $unassessed = Sermon::factory()->create(['video_quality_status' => SermonVideoQualityStatus::Unassessed]);
        $rejected = Sermon::factory()->create(['video_quality_status' => SermonVideoQualityStatus::Rejected]);

        $this->assertSame(SermonVideoQualityStatus::Unassessed, $unassessed->videoQualityStatus());
        $this->assertSame(SermonVideoQualityStatus::Rejected, $rejected->videoQualityStatus());
    }

    #[Test]
    public function it_returns_video_visibility_override_with_fallback(): void
    {
        // Columns are NOT NULL with defaults, so we test with explicit values
        $default = Sermon::factory()->create(['video_visibility_override' => SermonVideoVisibilityOverride::Default]);
        $forceShow = Sermon::factory()->create(['video_visibility_override' => SermonVideoVisibilityOverride::ForceShow]);

        $this->assertSame(SermonVideoVisibilityOverride::Default, $default->videoVisibilityOverride());
        $this->assertSame(SermonVideoVisibilityOverride::ForceShow, $forceShow->videoVisibilityOverride());
    }

    // ---- Thumbnail Status Helpers ----

    #[Test]
    public function it_reports_plain_thumbnail_status(): void
    {
        $withPlain = Sermon::factory()->create([
            'thumbnail_metadata' => ['plain_thumbnail_path' => 'thumbnails/plain.jpg'],
        ]);
        $withoutPlain = Sermon::factory()->create([
            'thumbnail_metadata' => ['plain_thumbnail_path' => null],
        ]);

        $this->assertTrue($withPlain->hasPlainThumbnail());
        $this->assertFalse($withoutPlain->hasPlainThumbnail());
    }

    #[Test]
    public function it_reports_card_thumbnail_status(): void
    {
        $withCard = Sermon::factory()->create([
            'thumbnail_metadata' => ['card_thumbnail_path' => 'thumbnails/card.jpg'],
        ]);
        $withoutCard = Sermon::factory()->create([
            'thumbnail_metadata' => ['card_thumbnail_path' => null],
        ]);

        $this->assertTrue($withCard->hasCardThumbnail());
        $this->assertFalse($withoutCard->hasCardThumbnail());
    }

    #[Test]
    public function it_reports_thumbnail_candidates_status(): void
    {
        $withCandidates = Sermon::factory()->create([
            'thumbnail_metadata' => [
                'thumbnail_candidates' => [
                    [
                        'id' => 'cand-1',
                        'timestamp' => 10.5,
                        'score' => 0.95,
                        'plain_path' => 'thumbnails/cand-1.jpg',
                    ],
                ],
            ],
        ]);
        $withoutCandidates = Sermon::factory()->create([
            'thumbnail_metadata' => [
                'thumbnail_candidates' => [],
            ],
        ]);

        $this->assertTrue($withCandidates->hasThumbnailCandidates());
        $this->assertFalse($withoutCandidates->hasThumbnailCandidates());
    }

    #[Test]
    public function it_reports_if_thumbnail_was_generated_from_video(): void
    {
        $fromVideo = Sermon::factory()->create([
            'thumbnail_metadata' => ['video_duration' => 120.0],
        ]);
        $manual = Sermon::factory()->create([
            'thumbnail_metadata' => null,
        ]);

        $this->assertTrue($fromVideo->hasVideoGeneratedThumbnail());
        $this->assertFalse($manual->hasVideoGeneratedThumbnail());
    }

    // ---- Scopes ----

    #[Test]
    public function it_has_from_livestream_scope(): void
    {
        $livestream = Sermon::factory()->create(['source_type' => SermonSourceType::Livestream]);
        $manual = Sermon::factory()->create(['source_type' => SermonSourceType::Manual]);

        $results = Sermon::fromLivestream()->get();

        $this->assertTrue($results->contains($livestream));
        $this->assertFalse($results->contains($manual));
    }

    #[Test]
    public function it_has_with_video_scope(): void
    {
        $withVideo = Sermon::factory()->create(['video_file_path' => 'video.mp4']);
        $withoutVideo = Sermon::factory()->create(['video_file_path' => null]);

        $results = Sermon::withVideo()->get();

        $this->assertTrue($results->contains($withVideo));
        $this->assertFalse($results->contains($withoutVideo));
    }

    #[Test]
    public function it_has_by_source_type_scope(): void
    {
        $livestream = Sermon::factory()->create(['source_type' => SermonSourceType::Livestream]);
        $upload = Sermon::factory()->create(['source_type' => SermonSourceType::AudioUpload]);

        $results = Sermon::bySourceType(SermonSourceType::Livestream)->get();

        $this->assertTrue($results->contains($livestream));
        $this->assertFalse($results->contains($upload));
    }

    #[Test]
    public function it_has_with_thumbnail_scope(): void
    {
        $withThumb = Sermon::factory()->create(['thumbnail_file_path' => 'thumb.jpg']);
        $withoutThumb = Sermon::factory()->create(['thumbnail_file_path' => null]);

        $results = Sermon::withThumbnail()->get();

        $this->assertTrue($results->contains($withThumb));
        $this->assertFalse($results->contains($withoutThumb));
    }

    #[Test]
    public function it_can_order_by_preacher_name(): void
    {
        $preacherA = Preacher::factory()->create(['name' => 'AAA - Test Preacher']);
        $preacherB = Preacher::factory()->create(['name' => 'ZZZ - Test Preacher']);

        $sermon1 = Sermon::factory()->create(['preacher_id' => $preacherB->id, 'preacher' => 'ZZZ - Test Preacher']);
        $sermon2 = Sermon::factory()->create(['preacher_id' => $preacherA->id, 'preacher' => 'AAA - Test Preacher']);
        $sermon3 = Sermon::factory()->create(['preacher_id' => null, 'preacher' => 'MMM - Test Preacher']);

        // MySQL ASC sorts NULL first.
        // Sermon 3 has preacher_id NULL, so its profile name is NULL.
        // Sermon 2 has preacher_id A, profile name 'AAA...'
        // Sermon 1 has preacher_id B, profile name 'ZZZ...'

        $results = Sermon::whereIn('id', [$sermon1->id, $sermon2->id, $sermon3->id])
            ->orderByPreacherName('asc')
            ->get();

        $this->assertCount(3, $results);
        $this->assertEquals($sermon3->id, $results[0]->id); // NULL profile name
        $this->assertEquals($sermon2->id, $results[1]->id); // AAA...
        $this->assertEquals($sermon1->id, $results[2]->id); // ZZZ...
    }
}
