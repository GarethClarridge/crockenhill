<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\Song\UnmatchedSongReviewApplicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UnmatchedSongReviewApplicatorTest extends TestCase
{
    use RefreshDatabase;

    private UnmatchedSongReviewApplicator $applicator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->applicator = app(UnmatchedSongReviewApplicator::class);
    }

    /**
     * The service mutates sections in-memory; callers are responsible for persisting.
     *
     * @param  Collection<int, ServiceSection>  $unmatched
     */
    private function persistUnmatched(Collection $unmatched): void
    {
        foreach ($unmatched as $section) {
            $section->save();
        }
    }

    #[Test]
    public function it_returns_an_empty_collection_when_all_song_sections_are_matched(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();

        $unmatched = $this->applicator->apply($sections, [$section->id]);

        $this->assertCount(0, $unmatched);
    }

    #[Test]
    public function it_flags_an_unmatched_song_section_for_manual_review(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $unmatched = $this->applicator->apply($sections, []);
        $this->persistUnmatched($unmatched);

        $this->assertCount(1, $unmatched);
        $this->assertTrue($section->fresh()->needs_manual_review);
    }

    #[Test]
    public function it_appends_the_unmatched_song_section_review_flag(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $unmatched = $this->applicator->apply($sections, []);
        $this->persistUnmatched($unmatched);

        $freshMetadata = $section->fresh()->metadata?->toArray() ?? [];
        $this->assertContains('unmatched_song_section', $freshMetadata['review_flags'] ?? []);
    }

    #[Test]
    public function it_sets_song_match_type_to_unmatched_when_not_previously_set(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => null,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $unmatched = $this->applicator->apply($sections, []);
        $this->persistUnmatched($unmatched);

        $this->assertSame(ServiceSectionSongMatchType::Unmatched, $section->fresh()->song_match_type);
    }

    #[Test]
    public function it_decreases_confidence_on_unmatched_song_sections(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'confidence' => 0.9,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $unmatched = $this->applicator->apply($sections, []);
        $this->persistUnmatched($unmatched);

        $this->assertLessThan(0.9, $section->fresh()->confidence);
    }

    #[Test]
    public function it_reclassifies_a_speech_detected_unmatched_song_as_other(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => null,
            'confidence' => 0.72,
            'needs_manual_review' => false,
            'metadata' => [
                'confidence_level' => 'high',
                'classification_mode' => 'ai_transcript',
                'detected_segment_class' => 'speech',
            ],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $mutated = $this->applicator->apply($sections, []);
        $this->persistUnmatched($mutated);

        $fresh = $section->fresh();
        // A spoken song-announcement is not a sung item: retype it and clear the
        // song-match state so no review path (song-match or manual-review) flags it.
        $this->assertSame(ServiceSectionType::Other, $fresh->section_type);
        $this->assertNull($fresh->song_match_type);
        $this->assertFalse($fresh->needs_manual_review);
        $this->assertNotContains('unmatched_song_section', $fresh->metadata?->toArray()['review_flags'] ?? []);
        // No confidence penalty — it is being reclassified, not doubted as a song.
        $this->assertEqualsWithDelta(0.72, (float) $fresh->confidence, 0.0001);
    }

    #[Test]
    public function it_still_flags_a_music_detected_unmatched_song(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'needs_manual_review' => false,
            'metadata' => [
                'confidence_level' => 'high',
                'classification_mode' => 'ai_transcript',
                'detected_segment_class' => 'song',
            ],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $this->persistUnmatched($this->applicator->apply($sections, []));

        $fresh = $section->fresh();
        $this->assertSame(ServiceSectionType::Song, $fresh->section_type);
        $this->assertTrue($fresh->needs_manual_review);
        $this->assertContains('unmatched_song_section', $fresh->metadata?->toArray()['review_flags'] ?? []);
    }

    #[Test]
    public function it_does_not_flag_non_song_sections(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();

        $unmatched = $this->applicator->apply($sections, []);

        $this->assertCount(0, $unmatched);
    }

    #[Test]
    public function it_does_not_overwrite_an_existing_review_reason(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'metadata' => [
                'confidence_level' => 'high',
                'classification_mode' => 'audio_only',
                'review_reason' => 'pre_existing_reason',
            ],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $unmatched = $this->applicator->apply($sections, []);
        $this->persistUnmatched($unmatched);

        $freshMetadata = $section->fresh()->metadata?->toArray() ?? [];
        $this->assertSame('pre_existing_reason', $freshMetadata['review_reason']);
    }
}
