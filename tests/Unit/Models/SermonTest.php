<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Data\ThumbnailMetadata;
use App\Enums\ProcessingStatus;
use App\Enums\SermonVideoQualityStatus;
use App\Enums\SermonVideoVisibilityOverride;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Support\SermonProcessingState;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function attribute_setters_trim_whitespace(): void
    {
        $sermon = new Sermon;

        $sermon->title = '  The Grace of God  ';
        $this->assertSame('The Grace of God', $sermon->title);

        $sermon->reference = '  John 3:16  ';
        $this->assertSame('John 3:16', $sermon->reference);

        $sermon->preacher = '  John Smith  ';
        $this->assertSame('John Smith', $sermon->preacher);

        $sermon->series = '  Gospel of John  ';
        $this->assertSame('Gospel of John', $sermon->series);
    }

    #[Test]
    public function reference_setters_handle_nulls_and_blanks(): void
    {
        $sermon = new Sermon;

        $sermon->reference = '';
        $this->assertNull($sermon->reference);

        $sermon->reference = '   ';
        $this->assertNull($sermon->reference);

        $sermon->reference = null;
        $this->assertNull($sermon->reference);
    }

    #[Test]
    public function preacher_setters_handle_nulls_and_blanks(): void
    {
        $sermon = new Sermon;

        $sermon->preacher = '';
        $this->assertNull($sermon->preacher);

        $sermon->preacher = '   ';
        $this->assertNull($sermon->preacher);

        $sermon->preacher = null;
        $this->assertNull($sermon->preacher);
    }

    #[Test]
    public function series_setters_handle_nulls_and_blanks(): void
    {
        $sermon = new Sermon;

        $sermon->series = '';
        $this->assertNull($sermon->series);

        $sermon->series = '   ';
        $this->assertNull($sermon->series);

        $sermon->series = null;
        $this->assertNull($sermon->series);
    }

    #[Test]
    public function is_automated_returns_false_for_unpersisted_sermon(): void
    {
        $sermon = new Sermon;
        $this->assertFalse($sermon->isAutomated());
    }

    #[Test]
    public function is_automated_returns_true_if_transcript_is_present(): void
    {
        $sermon = new Sermon(['transcript_file_path' => 'transcripts/test.txt']);
        $this->assertTrue($sermon->isAutomated());
    }

    #[Test]
    public function is_automated_uses_loaded_latest_processing_log_relation(): void
    {
        $sermon = new Sermon;
        $sermon->exists = true;

        $log = new MediaProcessingLog;
        $sermon->setRelation('latestProcessingLog', $log);

        $this->assertTrue($sermon->isAutomated());

        $sermon->setRelation('latestProcessingLog', null);
        $this->assertFalse($sermon->isAutomated());
    }

    #[Test]
    public function is_automated_uses_loaded_processing_logs_collection(): void
    {
        $sermon = new Sermon;
        $sermon->exists = true;

        $sermon->setRelation('processingLogs', collect([new MediaProcessingLog]));
        $this->assertTrue($sermon->isAutomated());

        $sermon->setRelation('processingLogs', collect([]));
        $this->assertFalse($sermon->isAutomated());
    }

    #[Test]
    public function is_automated_falls_back_to_database_check_if_relations_not_loaded(): void
    {
        $sermon = Sermon::factory()->create();

        // No relations loaded, no transcript
        $sermon->transcript_file_path = null;
        $this->assertFalse($sermon->isAutomated());

        // Add a log
        MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id]);

        $this->assertTrue($sermon->isAutomated());
    }

    #[Test]
    public function it_identifies_manual_sermons(): void
    {
        $sermon = new Sermon;
        $this->assertTrue($sermon->isManual());

        $sermon->transcript_file_path = 'transcripts/test.txt';
        $this->assertFalse($sermon->isManual());
    }

    #[Test]
    public function thumbnail_accessors_retrieve_paths_from_metadata(): void
    {
        $metadata = new ThumbnailMetadata(
            timestamp: null,
            videoDuration: null,
            plainThumbnailPath: 'thumbnails/plain.webp',
            cardThumbnailPath: 'thumbnails/card.webp',
        );

        $sermon = new Sermon(['thumbnail_metadata' => $metadata]);

        $this->assertSame('thumbnails/plain.webp', $sermon->plain_thumbnail_file_path);
        $this->assertSame('thumbnails/card.webp', $sermon->card_thumbnail_file_path);
    }

    #[Test]
    public function card_thumbnail_falls_back_to_plain_thumbnail(): void
    {
        $metadata = new ThumbnailMetadata(
            timestamp: null,
            videoDuration: null,
            plainThumbnailPath: 'thumbnails/plain.webp',
            cardThumbnailPath: null,
        );

        $sermon = new Sermon(['thumbnail_metadata' => $metadata]);

        $this->assertSame('thumbnails/plain.webp', $sermon->card_thumbnail_file_path);
    }

    #[Test]
    public function has_video_generated_thumbnail_logic(): void
    {
        $sermon = new Sermon;
        $this->assertFalse($sermon->hasVideoGeneratedThumbnail());

        // Metadata with just paths doesn't count as video-generated if other fields are empty
        $sermon->thumbnail_metadata = new ThumbnailMetadata(timestamp: null, videoDuration: 1800.0);
        $this->assertTrue($sermon->hasVideoGeneratedThumbnail());

        $sermon->thumbnail_metadata = new ThumbnailMetadata(
            timestamp: null,
            videoDuration: null,
            thumbnailCandidates: [['id' => 'cand-1', 'timestamp' => 10.0, 'score' => 0.9, 'plain_path' => 'p.jpg']]
        );
        $this->assertTrue($sermon->hasVideoGeneratedThumbnail());

        $sermon->thumbnail_metadata = new ThumbnailMetadata(timestamp: null, videoDuration: null, selectedThumbnailCandidateId: 'cand-1');
        $this->assertTrue($sermon->hasVideoGeneratedThumbnail());
    }

    #[Test]
    public function video_quality_status_returns_unassessed_by_default(): void
    {
        $sermon = new Sermon;
        $this->assertSame(SermonVideoQualityStatus::Unassessed, $sermon->videoQualityStatus());

        $sermon->video_quality_status = SermonVideoQualityStatus::Approved;
        $this->assertSame(SermonVideoQualityStatus::Approved, $sermon->videoQualityStatus());
    }

    #[Test]
    public function video_visibility_override_returns_default_by_default(): void
    {
        $sermon = new Sermon;
        $this->assertSame(SermonVideoVisibilityOverride::Default, $sermon->videoVisibilityOverride());

        $sermon->video_visibility_override = SermonVideoVisibilityOverride::ForceShow;
        $this->assertSame(SermonVideoVisibilityOverride::ForceShow, $sermon->videoVisibilityOverride());
    }

    #[Test]
    public function processing_state_returns_sermon_processing_state_object(): void
    {
        $sermon = new Sermon;
        $state = $sermon->processingState();

        $this->assertInstanceOf(SermonProcessingState::class, $state);
        $this->assertNull($state->log());

        $log = new MediaProcessingLog(['status' => ProcessingStatus::Completed]);
        $sermon->setRelation('latestProcessingLog', $log);

        $state = $sermon->processingState();
        $this->assertSame($log, $state->log());
        $this->assertTrue($state->isComplete());
    }
}
